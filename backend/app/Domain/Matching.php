<?php
namespace App\Domain;
use App\Models\DomainRecord;
use Illuminate\Support\Facades\DB;
class Matching
{
    public function snapshot(DomainRecord $policy): array
    {
        if ($policy->state!=='onaylandi') throw new DomainError('POLICY_NOT_APPROVED','Eşleştirme politikası onaylanmadı.');
        $applications=Records::query('applications')->where('term_id',$policy->term_id)->where('program_id',$policy->program_id)->orderBy('id')->get();
        $offers=Records::query('offers')->where('term_id',$policy->term_id)->where('program_id',$policy->program_id)->orderBy('id')->get();
        $students=[]; $offerData=[]; $pairs=[]; $dependencies=[]; $sites=[]; $trainers=[];
        foreach ($applications as $a) {
            $fixed=Records::query('placements')->where('student_id',$a->student_id)->where('term_id',$a->term_id)->whereIn('state',Eligibility::ACTIVE)->first();
            $students[]=['id'=>$a->id,'student_id'=>$a->student_id,'gpa'=>$a->gpa_snapshot,'fixed_offer_id'=>$fixed?->offer_id];
            $dependencies['applications:'.$a->id]=$a->version;
            foreach ($offers as $o) {
                $check=app(Eligibility::class)->pair($a,$o); $pref=$check['preference'];
                if ($fixed?->offer_id===$o->id && !$check['eligible']) throw new DomainError('SABIT_ATAMA_UYGUN_DEGIL','Sabit yerleştirme zorunlu koşulları sağlamıyor; yetkili inceleme gerekiyor.');
                $transport=null; $score=null; $preference=null;
                if ($pref && $pref->one_way_minutes!==null && $pref->transport_verified) {
                    foreach ($policy->transport_rules as $rule) if ($pref->one_way_minutes<=($rule['max_minutes']??PHP_INT_MAX)) { $transport=$rule['score']; break; }
                    $preference=100*($policy->max_preferences-$pref->rank+1)/$policy->max_preferences;
                    if ($transport!==null) $score=$policy->preference_weight*$preference+$policy->transport_weight*$transport;
                }
                if ($pref && ($transport===null || $pref->rank>$policy->max_preferences)) { $check['eligible']=false; $check['reasons'][]='Ulaşım puan tablosu veya tercih sırası bu çifti kapsamıyor; inceleme gerekiyor.'; $score=null; }
                if ($fixed?->offer_id===$o->id && !$check['eligible']) throw new DomainError('SABIT_ATAMA_UYGUN_DEGIL','Sabit yerleştirmenin puan politikası koşulları sağlanmıyor; yetkili inceleme gerekiyor.');
                $pairs[]=['student_id'=>$a->id,'offer_id'=>$o->id,'eligible'=>$check['eligible'],'reasons'=>$check['reasons'],'rank'=>$pref?->rank,'preference_score'=>$preference,'transport_score'=>$transport,'score'=>$score];
                if ($pref) $dependencies['preferences:'.$pref->id]=$pref->version;
                $i=Records::query('interviews')->where('application_id',$a->id)->where('offer_id',$o->id)->first();
                if ($i) $dependencies['interviews:'.$i->id]=$i->version;
            }
        }
        foreach ($offers as $o) {
            $placed=Records::query('placements')->where('offer_id',$o->id)->whereIn('state',Eligibility::ACTIVE)->count();
            $offerData[]=['id'=>$o->id,'site_id'=>$o->site_id,'trainer_id'=>$o->trainer_id,'capacity'=>max(0,$o->capacity-$placed)];
            foreach (['offers'=>$o->id,'protocols'=>$o->protocol_id,'company_assessments'=>$o->assessment_id,'trainer_qualifications'=>$o->trainer_id,'company_sites'=>$o->site_id,'companies'=>$o->company_id] as $type=>$id) $dependencies[$type.':'.$id]=Records::get($type,$id)->version;
            foreach (['protocols'=>$o->protocol_id,'trainer_qualifications'=>$o->trainer_id] as $type=>$id) { $record=Records::get($type,$id); foreach(config("domain.$type.fields") as $key=>$field) if($field['ref']==='documents' && $record->$key) $dependencies['documents:'.$record->$key]=Records::get('documents',$record->$key)->version; }
            $site=Records::get('company_sites',$o->site_id);
            $siteUsed=Records::query('placements')->where('term_id',$o->term_id)->whereIn('state',Eligibility::ACTIVE)->whereIn('offer_id',Records::query('offers')->where('site_id',$site->id)->select('id'))->count();
            $sites[$site->id]=['id'=>$site->id,'capacity'=>$site->capacity_type==='ortak'?max(0,$site->total_capacity-$siteUsed):null];
            $trainerUsed=Records::query('placements')->where('term_id',$o->term_id)->whereIn('state',Eligibility::ACTIVE)->whereIn('offer_id',Records::query('offers')->where('trainer_id',$o->trainer_id)->select('id'))->count();
            $trainers[$o->trainer_id]=['id'=>$o->trainer_id,'capacity'=>max(0,5-$trainerUsed)];
        }
        foreach (['protocol_scopes','preferences','interviews','company_performance'] as $type) {
            foreach (Records::query($type)->where('institution_id',$policy->institution_id)->orderBy('id')->get() as $r) $dependencies[$type.':'.$r->id]=$r->version;
        }
        ksort($dependencies);
        return ['schema_version'=>1,'algorithm_version'=>'mue-cpsat-1.0','policy_id'=>$policy->id,'policy_version'=>$policy->version,'seed'=>$policy->seed,'time_limit_seconds'=>$policy->time_limit_seconds,'weights'=>['preference'=>$policy->preference_weight,'transport'=>$policy->transport_weight],'students'=>$students,'offers'=>$offerData,'sites'=>array_values($sites),'trainers'=>array_values($trainers),'candidates'=>$pairs,'dependencies'=>$dependencies,'collection_hash'=>$this->collectionHash($policy)];
    }

    public function hash(array $snapshot): string
    {
        $canonical=function($v)use(&$canonical){ if(!is_array($v))return $v; if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($canonical,$v); };
        return hash('sha256',json_encode($canonical($snapshot),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    public function validate(array $snapshot,array $result): void
    {
        if (!in_array($result['solver_status']??'', ['OPTIMAL','FEASIBLE'])) throw new DomainError('RUN_FAILED','Çözücü uygulanabilir öneri üretmedi.');
        if(($result['algorithm_version']??null)!==$snapshot['algorithm_version'] || !is_array($result['assignments']??null) || !is_array($result['unplaced']??null))throw new DomainError('RUN_INVALID','Çözücü sürümü veya sonuç sözleşmesi geçersiz.');
        $used=[]; $sites=[]; $trainers=[]; $students=[]; $valid=[];
        foreach ($snapshot['candidates'] as $p) if ($p['eligible']) $valid[$p['student_id'].':'.$p['offer_id']]=true;
        $offers=array_column($snapshot['offers'],null,'id');
        foreach ($result['assignments'] as $pair) {
            $student=$pair['student_id']; $oid=$pair['offer_id'];
            if (isset($students[$student]) || !isset($valid[$student.':'.$oid]) || !isset($offers[$oid])) throw new DomainError('RUN_INVALID','Öneri zorunlu eşleştirme koşullarını ihlal ediyor.');
            $students[$student]=true; $used[$oid]=($used[$oid]??0)+1;
            $site=$offers[$oid]['site_id']; $sites[$site]=($sites[$site]??0)+1;
            $trainer=$offers[$oid]['trainer_id']; $trainers[$trainer]=($trainers[$trainer]??0)+1;
        }
        foreach ($offers as $id=>$o) if (($used[$id]??0)>$o['capacity']) throw new DomainError('RUN_INVALID','Öneride kontenjan aşımı var.');
        foreach ($snapshot['sites'] as $s) if ($s['capacity']!==null && ($sites[$s['id']]??0)>$s['capacity']) throw new DomainError('RUN_INVALID','Ortak şube kapasitesi aşıldı.');
        foreach ($snapshot['trainers'] as $t) if (($trainers[$t['id']]??0)>$t['capacity']) throw new DomainError('RUN_INVALID','Eğitici öğrenci sınırı aşıldı.');
        $known=array_column($snapshot['students'],null,'id'); $unplaced=[];
        foreach ($result['unplaced'] as $u) { if (!isset($known[$u['student_id']]) || isset($students[$u['student_id']]) || isset($unplaced[$u['student_id']])) throw new DomainError('RUN_INVALID','Yerleşemeyen öğrenci listesi tutarsız.'); $unplaced[$u['student_id']]=true; }
        foreach ($known as $id=>$s) if (!$s['fixed_offer_id'] && !isset($students[$id]) && !isset($unplaced[$id])) throw new DomainError('RUN_INVALID','Sonuçta öğrenci kaydı eksik.');
        foreach ($result['assignments'] as $p) if (!isset($known[$p['student_id']]) || $known[$p['student_id']]['fixed_offer_id']) throw new DomainError('RUN_INVALID','Sabit atama yeniden önerilemez.');
    }
    public function assertFresh(DomainRecord $run): void
    {
        if ($run->snapshot_hash!==$this->hash($run->snapshot)) throw new DomainError('STALE_VERSION','Anlık görüntünün bütünlüğü bozuldu.');
        foreach($run->snapshot['dependencies'] as $key=>$version) {
            [$type,$id]=explode(':',$key,2);
            $record=Records::query($type)->find($id);
            if(!$record || $record->version!==$version) throw new DomainError('STALE_VERSION','Eşleştirme verileri değişti; yeni çalışma oluşturun.');
        }
        $policy=Records::get('matching_policies',$run->policy_id);
        if (isset($run->snapshot['collection_hash']) && $run->snapshot['collection_hash']!==$this->collectionHash($policy,$run->id)) throw new DomainError('STALE_VERSION','Aday verileri veya etkin yerleştirmeler değişti; yeni çalışma oluşturun.');
        if($policy->version!==$run->snapshot['policy_version'] || $policy->state!=='onaylandi') throw new DomainError('STALE_VERSION','Eşleştirme politikası değişti.');
    }
    private function collectionHash(DomainRecord $policy,?string $runId=null): string
    {
        $data=[];
        foreach (['applications','offers','preferences','interviews','protocol_scopes','company_performance','academic_terms','term_programs','placements'] as $type) {
            $q=Records::query($type)->where('institution_id',$policy->institution_id);
            if (in_array($type,['applications','offers','preferences','interviews','term_programs'])) $q->where('term_id',$policy->term_id)->where('program_id',$policy->program_id);
            if ($type==='academic_terms') $q->where('id',$policy->term_id);
            if ($type==='placements') { $q->where('term_id',$policy->term_id)->whereIn('state',Eligibility::ACTIVE); if($runId)$q->where(fn($q)=>$q->whereNull('run_id')->orWhere('run_id','!=',$runId)); }
            $data[$type]=$q->orderBy('id')->get(['id','version'])->toArray();
        }
        return $this->hash($data);
    }
}
