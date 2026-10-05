<?php
namespace App\Http\Controllers;
use App\Domain\{Records,ScopeAccess,DomainError,Education};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};
use Dompdf\Dompdf;
class ReportController extends ResourceController
{
    private function authority(): void
    { if(!in_array(app(ScopeAccess::class)->assignment(auth()->user())->role,['mudur','mue_komisyon','bolum_komisyon','program_baskani','koordinator'])) throw new DomainError('FORBIDDEN_SCOPE','Kurumsal rapor yetkisi gerekir.',403); }
    public function export(Request $req,string $resource)
    {
        $this->authority(); $req->validate(['format'=>'required|in:csv,xlsx,pdf']);
        $rows=[];$cursor=null;
        do { $req->merge(['limit'=>100,'cursor'=>$cursor]); $page=$this->index($req,$resource);$rows=array_merge($rows,$page['items']);$cursor=$page['next_cursor']; if(count($rows)>10000) throw new DomainError('RAPOR_SINIRI','Dışa aktarma için filtreyi daraltın.',422); } while($cursor);
        $a=app(ScopeAccess::class)->assignment($req->user()); DB::table('audit_events')->insert(['actor_id'=>auth()->id(),'assignment_id'=>$a->id,'institution_id'=>$a->institution_id,'action'=>'EXPORT','target_type'=>$resource,'request_id'=>$req->attributes->get('request_id'),'occurred_at'=>now()]);
        $headers=array_keys($rows[0]??['bilgi'=>'Kayıt yok']);
        $portal=$req->is('portal-api/*');
        $translate=function($v)use(&$translate){if(is_bool($v))return $v?'Evet':'Hayır';if(is_array($v)){if(array_is_list($v))return array_map($translate,$v);$out=[];foreach($v as $k=>$x)$out[config('portal.fields.'.$k,config('portal.labels.'.$k,$k))]=$translate($x);return $out;}return is_string($v)?config('portal.values.'.$v,config('portal.labels.'.$v,$v)):$v;};
        $table=[$portal?array_map(fn($k)=>config('portal.fields.'.$k,$k),$headers):$headers]; foreach($rows as $row) $table[]=array_map(function($v)use($portal,$translate){if($portal)$v=$translate($v);$s=is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE):(string)$v;return preg_match('/^[=+@\-\t\r]/',$s)?"'".$s:$s;},array_values($row));
        if($req->format==='csv') return response()->streamDownload(function()use($table){$h=fopen('php://output','w');fwrite($h,"\xEF\xBB\xBF");foreach($table as $r)fputcsv($h,$r);fclose($h);},'mue-rapor.csv',['Content-Type'=>'text/csv; charset=utf-8']);
        if($req->format==='xlsx') return response()->streamDownload(function()use($table){$sheet=new Spreadsheet();foreach($table as $i=>$row)foreach($row as $j=>$v)$sheet->getActiveSheet()->setCellValueExplicit([$j+1,$i+1],$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);(new Xlsx($sheet))->save('php://output');},'mue-rapor.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        $html='<html lang="tr"><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:8px}td,th{border:1px solid #777;padding:3px;word-wrap:break-word}table{border-collapse:collapse;width:100%}</style><h1>Mesleki Uygulama Eğitimi Raporu</h1><table>';
        foreach($table as $row){$html.='<tr>';foreach($row as $v)$html.='<td>'.htmlspecialchars($v,ENT_QUOTES,'UTF-8').'</td>';$html.='</tr>';}$html.='</table></html>';
        $pdf=new Dompdf(['isRemoteEnabled'=>false]);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A3','landscape');$pdf->render();return response($pdf->output())->header('Content-Type','application/pdf')->header('Content-Disposition','attachment; filename="mue-rapor.pdf"');
    }
    public function report(Request $req,string $report)
    {
        $this->authority();
        if($report==='audit') {
            $a=app(ScopeAccess::class)->assignment($req->user()); if(!in_array($a->role,['mudur','mue_komisyon'])) throw new DomainError('FORBIDDEN_SCOPE','Denetim izi için kurum yetkisi gerekir.',403);
            return ['items'=>DB::table('audit_events')->where('institution_id',$a->institution_id)->orderByDesc('id')->limit(100)->get()];
        }
        $placementPage=$this->index($req->merge(['limit'=>100]),'placements'); $placements=$placementPage['items'];
        return match($report) {
            'dashboard'=>['placements'=>$this->index($req,'placements')['total'],'applications'=>$this->index($req,'applications')['total'],'companies'=>$this->index($req,'companies')['total'],'offers'=>$this->index($req,'offers')['total'],'matching_results'=>$this->index($req,'match_results')],
            'monitoring'=>array_merge($placementPage,['items'=>array_map(fn($p)=>['placement_id'=>$p['id'],'monitoring'=>app(Education::class)->monitoring(Records::get('placements',$p['id']))],$placements)]),
            'attendance'=>array_merge($placementPage,['items'=>array_map(fn($p)=>['placement_id'=>$p['id'],'attendance'=>app(Education::class)->attendanceSummary(Records::get('placements',$p['id']))],$placements)]),
            'company-history'=>$this->companyHistory($req),
            'outcomes'=>$this->outcomes($req),
            default=>throw new DomainError('RAPOR_BULUNAMADI','Rapor bulunamadı.',404),
        };
    }
    private function companyHistory(Request $r): array
    { $r->validate(['company_id'=>'required|uuid']); $company=Records::get('companies',$r->company_id);\Illuminate\Support\Facades\Gate::authorize('record-view',$company);$out=[];foreach(['placements','interviews','inspections','company_performance','feedback','incidents'] as $type)$out[$type]=$this->index($r,$type);return $out; }
    private function outcomes(Request $req): array
    {
        $req->validate(['term_id'=>'required|uuid']);
        $data=[];$cursor=null;
        do { $page=$this->index($req->merge(['limit'=>100,'cursor'=>$cursor]),'rubric_evaluations');$data=array_merge($data,$page['items']);$cursor=$page['next_cursor'];if(count($data)>10000)throw new DomainError('RAPOR_SINIRI','Kazanım raporu için dönem/program filtresini daraltın.',422); } while($cursor);
        usort($data,fn($a,$b)=>$b['revision']<=>$a['revision']);$latest=[];$groups=[];
        $cohort=[];$cursor=null;do{$page=$this->index($req->merge(['limit'=>100,'cursor'=>$cursor]),'placements');foreach($page['items'] as $p)$cohort[$p['program_id']][$p['student_id']]=[];$cursor=$page['next_cursor'];}while($cursor);
        foreach(Records::query('learning_outcomes')->whereIn('program_id',array_keys($cohort))->get() as $o){\Illuminate\Support\Facades\Gate::authorize('record-view',$o);$groups[$o->id]=$cohort[$o->program_id];}
        foreach($data as $r)if($r['state']==='onaylandi') { $key=$r['student_id'].':'.$r['outcome_id'].':'.$r['source'].':'.$r['area'];if(isset($latest[$key]))continue;$latest[$key]=true;$groups[$r['outcome_id']][$r['student_id']][$r['source']][$r['area']]=$r['score']; }
        $out=[];$outputs=[];
        foreach($groups as $id=>$students) {
            $scores=[];$incomplete=0;$outcome=Records::get('learning_outcomes',$id);$output=Records::get('program_outputs',$outcome->program_output_id);
            foreach($students as $sid=>$student) {
                $sum=0;$complete=true;foreach(['is_yeri'=>.4,'danisman'=>.3,'dosya_portfolyo'=>.2,'sunum'=>.1] as $source=>$weight)foreach(['mesleki'=>30,'problem'=>20,'takim'=>15,'etik'=>15,'isg'=>10,'belgeleme'=>10] as $area=>$max) { if(!isset($student[$source][$area])){$complete=false;continue;}$sum+=$student[$source][$area]*$max/100*$weight; }
                $outputs[$output->id]['students'][$sid][]=$complete?$sum:null;
                if($complete)$scores[]=$sum;else $incomplete++;
            }
            $outputs[$output->id]['target']=$output->target_percent;$out[]=['outcome_id'=>$id,'program_output_id'=>$output->id]+$this->attainment($scores,$incomplete,$output->target_percent);
        }
        $summary=[];foreach($outputs as $id=>$group){$scores=[];$incomplete=0;foreach($group['students'] as $values){if(in_array(null,$values,true)){$incomplete++;continue;}$scores[]=array_sum($values)/count($values);}$summary[]=['program_output_id'=>$id]+$this->attainment($scores,$incomplete,$group['target']);}
        return ['learning_outcomes'=>$out,'program_outputs'=>$summary];
    }
    private function attainment(array $scores,int $incomplete,float $target): array
    { $count=count($scores);$ratio=$count?100*count(array_filter($scores,fn($s)=>$s>=60))/$count:null;return ['average'=>$count?array_sum($scores)/$count:null,'above_60_percent'=>$ratio,'target_percent'=>$target,'target_met'=>$ratio===null?null:$ratio>=$target,'evaluated_students'=>$count,'incomplete_students'=>$incomplete]; }
}
