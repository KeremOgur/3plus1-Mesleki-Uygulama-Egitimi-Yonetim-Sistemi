<?php
namespace App\Http\Controllers;
use App\Domain\{Records,ScopeAccess,DomainError};
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\{Validator,Hash};
use PhpOffice\PhpSpreadsheet\IOFactory;
class ImportController extends ResourceController
{
    public function preview(Request $req)
    {
        $req->validate(['file'=>'required|file|max:10240|mimes:csv,txt,xlsx','institution_id'=>'required|uuid']);
        return $this->transaction($req,function($a)use($req){
            if(!in_array($a->role,['bolum_komisyon','program_baskani']) || $a->institution_id!==$req->institution_id) throw new DomainError('FORBIDDEN_SCOPE','Öğrenci aktarım yetkiniz yok.',403);
            $file=$req->file('file'); $hash=hash_file('sha256',$file->getRealPath());
            if($prior=Records::query('import_batches')->where('institution_id',$a->institution_id)->where('sha256',$hash)->first()) { \Illuminate\Support\Facades\Gate::authorize('record-view',$prior);return $prior; }
            if(strtolower($file->getClientOriginalExtension())==='xlsx') {
                $reader=IOFactory::createReader('Xlsx'); $reader->setReadDataOnly(true); $rows=$reader->load($file->getRealPath())->getActiveSheet()->toArray();
            } else {
                if(!mb_check_encoding(file_get_contents($file->getRealPath()),'UTF-8')) throw new DomainError('CSV_KODLAMA','CSV dosyası UTF-8 olmalıdır.',422);
                $rows=[]; $h=fopen($file->getRealPath(),'r'); while(($row=fgetcsv($h,0,','))!==false) $rows[]=$row; fclose($h);
            }
            if(count($rows)>10001) throw new DomainError('AKTARIM_SINIRI','Tek aktarımda en fazla 10.000 öğrenci işlenebilir.',422);
            $headers=array_map(fn($v)=>trim((string)$v,"\xEF\xBB\xBF \t\r\n"),array_shift($rows)??[]);
            $required=['ogrenci_no','ad_soyad','program_kodu','donem_kodu','gno','gno_olcegi'];
            if(array_diff($required,$headers)) throw new DomainError('CSV_BASLIK','Gerekli CSV/XLSX başlıkları eksik.',422);
            $valid=[];$errors=[];$seen=[];
            foreach($rows as $i=>$values) {
                if(count($values)!==count($headers)) { $errors[]=['row'=>$i+2,'message'=>'Sütun sayısı uyuşmuyor.'];continue; }
                $r=array_combine($headers,$values); $validator=Validator::make($r,['ogrenci_no'=>'required|string|max:30','ad_soyad'=>'required|string|max:250','program_kodu'=>'required|string','donem_kodu'=>'required|string','gno'=>'required|numeric|between:0,4','gno_olcegi'=>'required|numeric|in:4']);
                $program=Records::query('programs')->where('institution_id',$a->institution_id)->where('code',$r['program_kodu'])->first();
                $term=Records::query('academic_terms')->where('institution_id',$a->institution_id)->where('code',$r['donem_kodu'])->first();
                if($validator->fails() || !$program || !$term || isset($seen[$r['ogrenci_no']]) || ($a->program_id && $program->id!==$a->program_id) || ($a->term_id && $term->id!==$a->term_id)) { $errors[]=['row'=>$i+2,'message'=>'Eksik alan, GNO aralığı, mükerrer öğrenci veya bilinmeyen/yetkisiz program/dönem.','fields'=>$validator->errors()->toArray()];continue; }
                $seen[$r['ogrenci_no']]=true; $r['program_id']=$program->id; $r['term_id']=$term->id; $valid[]=$r;
            }
            return Records::create('import_batches',['institution_id'=>$a->institution_id,'program_id'=>$a->program_id,'source'=>strtolower($file->getClientOriginalExtension()),'sha256'=>$hash,'rows'=>$valid,'errors'=>$errors,'accepted_count'=>0,'state'=>'onizleme']);
        });
    }
    public function approve(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','reason'=>'required|string']);
        return $this->transaction($req,function()use($req,$id){
            $batch=Records::query('import_batches')->lockForUpdate()->findOrFail($id); app(ScopeAccess::class)->assertAction('bolum_komisyon program_baskani',$batch); $this->version($req,$batch);
            if($batch->state==='onaylandi') return $batch;
            if($batch->errors) throw new DomainError('AKTARIM_HATALI','Hatalı satırları düzeltip yeniden önizleme yapın.');
            foreach($batch->rows as $row) {
                $student=Records::query('students')->where('institution_id',$batch->institution_id)->where('student_no',$row['ogrenci_no'])->first();
                if(!$student) {
                    $user=User::create(['name'=>$row['ad_soyad'],'email'=>Str::uuid().'@mue.invalid','password'=>Hash::make(Str::random(64)),'active'=>false]);
                    $student=Records::create('students',['institution_id'=>$batch->institution_id,'program_id'=>$row['program_id'],'user_id'=>$user->id,'student_no'=>$row['ogrenci_no'],'graduation_exception'=>false,'academic_data'=>[],'gpa'=>$row['gno'],'gpa_scale'=>$row['gno_olcegi'],'academic_source'=>'CSV/XLSX:'.$batch->sha256,'academic_fetched_at'=>now()]);
                } else {
                    if($student->program_id!==$row['program_id']) throw new DomainError('PROGRAM_DEGISIKLIGI','Öğrenci program değişikliği ayrı yetkili süreç gerektirir.');
                    $student->fill(['gpa'=>$row['gno'],'gpa_scale'=>$row['gno_olcegi'],'academic_source'=>'CSV/XLSX:'.$batch->sha256,'academic_fetched_at'=>now()])->save();
                }
            }
            $batch->fill(['state'=>'onaylandi','accepted_count'=>count($batch->rows)])->save(); return $batch->refresh();
        });
    }
}
