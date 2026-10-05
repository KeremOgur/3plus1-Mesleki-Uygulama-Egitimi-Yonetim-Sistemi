<?php
namespace App\Domain;
use App\Models\DomainRecord;

class Eligibility
{
    public const ACTIVE=['onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida'];
    public function offerReasons(DomainRecord $o): array
    {
        $why=[]; $t=Records::get('academic_terms',$o->term_id);
        $c=Records::get('companies',$o->company_id); $s=Records::get('company_sites',$o->site_id);
        $p=Records::get('protocols',$o->protocol_id); $a=Records::get('company_assessments',$o->assessment_id);
        $trainer=Records::get('trainer_qualifications',$o->trainer_id);
        if ($c->state!=='aktif') $why[]='İşletme aktif değil.';
        $performance=Records::query('company_performance')->where('site_id',$s->id)->where('state','onaylandi')->latest()->first();
        if($performance && ($performance->authorization_years===0 || $performance->authorization_until<$t->ends_on))$why[]='İşletmenin yeniden yetkilendirmesi eğitim aralığını kapsamıyor.';
        if (!in_array($o->state,['onaylandi','ilan_edildi'])) $why[]='Teklif onaylı değil.';
        if ($p->state!=='aktif' || $p->valid_from>$t->starts_on || $p->valid_until<$t->ends_on) $why[]='Protokol eğitim aralığını kapsamıyor.';
        if (!Records::query('protocol_scopes')->where('protocol_id',$p->id)->where('site_id',$s->id)->where('program_id',$o->program_id)->exists()) $why[]='Protokol şube/program kapsamı geçersiz.';
        if ($a->state!=='onaylandi' || $a->program_id!==$o->program_id || $a->site_id!==$s->id || $a->valid_from>$t->starts_on || $a->valid_until<$t->ends_on) $why[]='İşletme uygunluğu geçersiz.';
        foreach (['program_suitable','technical_infrastructure','qualified_trainer','ohs_suitable','physical_conditions','activity_diversity'] as $k) if (!$a->$k) $why[]='Zorunlu işletme uygunluk koşulu sağlanmıyor: '.$k;
        if ($trainer->state!=='onaylandi' || $trainer->site_id!==$s->id || $trainer->experience_years<3 || !$trainer->declaration_accepted) $why[]='Eğitici nitelikleri geçersiz.';
        foreach ([$p->signed_document_id,$trainer->qualification_document_id,$trainer->ohs_document_id] as $id) if (!$this->cleanDocument($id)) $why[]='Gerekli belge eksik veya taranmadı.';
        if ($o->capacity<=0) $why[]='Onaylı kontenjan yok.';
        if ($s->capacity_type==='ortak' && $s->total_capacity===null) $why[]='Ortak şube kapasitesi belirlenmedi.';
        return array_values(array_unique($why));
    }

    public function pair(DomainRecord $application, DomainRecord $offer, bool $capacity=false): array
    {
        $why=$this->offerReasons($offer);
        if ($application->state!=='uygun' || $application->eligibility!=='uygun') $why[]='Başvuru uygunluk kararı yok.';
        if ($application->term_id!==$offer->term_id || $application->program_id!==$offer->program_id) $why[]='Dönem veya program uyuşmuyor.';
        if ($application->gpa_snapshot===null) $why[]='GNO verisi eksik; inceleme gerekiyor.';
        $rev=Records::query('preferences')->where('application_id',$application->id)->whereNotNull('submitted_at')->max('revision');
        $pref=Records::query('preferences')->where('application_id',$application->id)->where('revision',$rev)->where('offer_id',$offer->id)->whereNotNull('submitted_at')->first();
        if (!$pref) $why[]='Kesinleşmiş tercih bulunmuyor.';
        elseif (!$pref->reachable || !$pref->transport_verified || $pref->one_way_minutes===null) $why[]='Ulaşım uygunluğu eksik veya doğrulanmadı.';
        $interview=Records::query('interviews')->where('application_id',$application->id)->where('offer_id',$offer->id)->first();
        if ($interview && $interview->result==='ret') $why[]='İşletme bu öğrenci–teklif çifti için gerekçeli ret bildirdi.';
        if (($offer->interview_required || $interview) && $interview?->result!=='kabul') $why[]='Talep edilen görüşmenin kabul sonucu yok.';
        if ($capacity && Records::query('placements')->where('offer_id',$offer->id)->whereIn('state',self::ACTIVE)->count()>=$offer->capacity) $why[]='Kontenjan dolu.';
        return ['eligible'=>!$why,'reasons'=>array_values(array_unique($why)),'preference'=>$pref];
    }

    public function cleanDocument(?string $id): bool
    { return $id && Records::query('documents')->where('id',$id)->where('scan_status','temiz')->exists(); }

    public function studentCanViewOfferResource(DomainRecord $student, DomainRecord $r): bool
    {
        $q=Records::query('offers')->where('institution_id',$student->institution_id)->where('program_id',$student->program_id)->where('state','ilan_edildi');
        $field=match ($r->getTable()) {'offers'=>'id','companies'=>'company_id','company_sites','risk_plans'=>'site_id','protocols'=>'protocol_id','company_assessments'=>'assessment_id','trainer_qualifications'=>'trainer_id',default=>null};
        if (!$field) return false;
        $ownPlacements=Records::query('placements')->where('student_id',$student->id)->whereIn('state',['ilan_edildi','baslamaya_hazir','basladi','tamamlandi','askida','degistirildi']);
        $id=$r->getTable()==='risk_plans'?$r->site_id:$r->id;
        if(Records::query('offers')->whereIn('id',$ownPlacements->select('offer_id'))->where($field,$id)->exists())return true;
        foreach ($q->where($field,$id)->get() as $o) if (!$this->offerReasons($o)) return true;
        return false;
    }

    public function ready(DomainRecord $p): array
    {
        $missing=[];
        if (!in_array($p->state,['ilan_edildi','baslamaya_hazir','basladi','askida'])) $missing[]='İlan edilmiş nihai yerleştirme';
        foreach ($this->offerReasons(Records::get('offers',$p->offer_id)) as $reason) $missing[]=$reason;
        if (!Records::query('insurance_records')->where('placement_id',$p->id)->where('state','onaylandi')->where('starts_on','<=',$p->starts_on)->where('ends_on','>=',$p->ends_on)->where('documents_complete',true)->whereNotNull('submitted_to_company_on')->exists()) $missing[]='Onaylı sigorta ve işe giriş bildirgesi';
        if (!Records::query('ohs_records')->where('placement_id',$p->id)->where('state','onaylandi')->where('trained_on','<=',$p->starts_on)->where('emergency_orientation',true)->exists()) $missing[]='İSG eğitimi, KKD ve acil durum oryantasyonu';
        foreach (['EK-8','EK-10'] as $form) if (!Records::query('declarations')->where('placement_id',$p->id)->where('form_code',$form)->where('accepted',true)->exists()) $missing[]=$form.' imzalı taahhüdü';
        if (!Records::query('trainer_assignments')->where('placement_id',$p->id)->where('valid_from','<=',$p->starts_on)->where('valid_until','>=',$p->ends_on)->exists()) $missing[]='Eğitici görevlendirmesi';
        if (!Records::query('adviser_assignments')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->where('valid_from','<=',$p->starts_on)->where('valid_until','>=',$p->ends_on)->exists()) $missing[]='Akademik danışman görevlendirmesi';
        foreach (['insurance_records','ohs_records','declarations'] as $type) {
            foreach (Records::query($type)->where('placement_id',$p->id)->whereIn('state',['onaylandi','kayitli'])->get() as $r) {
                foreach (config("domain.$type.fields") as $key=>$field) if ($field['ref']==='documents' && !$this->cleanDocument($r->$key)) $missing[]='Eksik veya temiz taranmamış başlama belgesi';
            }
        }
        return array_values(array_unique($missing));
    }
}
