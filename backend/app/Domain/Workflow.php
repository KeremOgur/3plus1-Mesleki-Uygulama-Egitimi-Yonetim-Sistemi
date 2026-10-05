<?php
namespace App\Domain;
use App\Models\DomainRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class Workflow
{
    public function transition(DomainRecord $r,string $to,array $input): DomainRecord
    {
        $type=$r->getTable(); $from=$r->state;
        if (!in_array($to,config("domain.$type.states.$from",[]))) throw new DomainError('GECERSIZ_GECIS','Bu durum geçişine izin verilmiyor.');
        $rule=$this->rulesFor($type,$to); $roleList=implode(' ',$rule['roles']); $decision=$rule['decision'];
        $assignment=app(ScopeAccess::class)->assertAction($roleList,$r);
        // Assigned observers and students retain the exact same record scope for actions.
        if (in_array($assignment->role,['ogrenci','akademik_danisman','egitici','isletme_yetkilisi'])) Gate::authorize('record-view',$r);
        if (in_array($to,['reddedildi','iade','ret','askida','feshedildi']) && empty($input['reason'])) throw new DomainError('GEREKCE_GEREKLI','Bu işlem için gerekçe zorunludur.',422);
        if ($decision) {
            $expected=match($to){'reddedildi','ret'=>'ret','askida'=>'askiya_alma','feshedildi'=>'fesih',default=>'onay'};
            if(($input['outcome']??null)!==$expected) throw new DomainError('KARAR_SONUCU','Karar sonucu durum geçişiyle uyuşmalıdır.',422);
            $this->decision($r,$input,$assignment);
        }
        if (in_array($to,['onaylandi','aktif','gonderildi','danisman_onayi','egitici_onayi'])) {
            foreach(config("domain.$type.fields") as $key=>$field) if($field['ref']==='documents' && $r->$key && !app(Eligibility::class)->cleanDocument($r->$key)) throw new DomainError('BELGE_EKSIK','İlgili belgenin temiz tarama sonucu gereklidir.');
        }
        if ($type==='applications') {
            $term=Records::get('academic_terms',$r->term_id);
            if ($to==='gonderildi' && now()->greaterThan($term->application_deadline)) throw new DomainError('DEADLINE_PASSED','Başvuru süresi sona erdi.');
            if (in_array($to,['uygun','eksik','uygun_degil'])) {
                app(ScopeAccess::class)->assertAction('bolum_komisyon program_baskani',$r);
                if(empty($input['reason'])) throw new DomainError('GEREKCE_GEREKLI','Uygunluk inceleme gerekçesi gereklidir.',422);
                $r->eligibility=$to; $r->eligibility_reason=$input['reason'];
            }
        }
        if ($type==='company_assessments' && $to==='onaylandi') foreach(['program_suitable','technical_infrastructure','qualified_trainer','ohs_suitable','physical_conditions','activity_diversity'] as $k) if(!$r->$k) throw new DomainError('UYGUNLUK_EKSIK','Zorunlu işletme uygunluk koşulu sağlanmıyor.');
        if ($type==='trainer_qualifications' && $to==='onaylandi') {
            foreach(['national_id','phone','email','education_records','experience_records'] as $field)if(empty($r->$field))throw new DomainError('EK19_EKSIK','EK-19 kimlik, iletişim, öğrenim ve deneyim alanları tamamlanmalıdır.',422);
            if($r->experience_years<3 || !app(Eligibility::class)->cleanDocument($r->qualification_document_id))throw new DomainError('EGITICI_NITELIGI','Eğitici en az üç yıl deneyim ve ilgili diploma veya yeterlik belgesi koşulunu sağlamalıdır.',422);
        }
        if ($type==='risk_plans' && $to==='onaylandi' && (!$r->general_assessment_current || !$r->student_units || !$r->orientation_hours || !app(Eligibility::class)->cleanDocument($r->orientation_document_id)))throw new DomainError('EK20_EKSIK','EK-20 güncel risk değerlendirmesi, öğrenci birimleri ve belgeli oryantasyon gerektirir.',422);
        if ($type==='offers' && in_array($to,['onaylandi','ilan_edildi'])) { $clone=clone $r; $clone->state='onaylandi'; $why=app(Eligibility::class)->offerReasons($clone); if($why) throw new DomainError('TEKLIF_UYGUN_DEGIL',implode(' ',$why)); }
        if ($type==='protocols' && $to==='aktif') {
            if(!app(Eligibility::class)->cleanDocument($r->signed_document_id)) throw new DomainError('BELGE_EKSIK','İmzalı protokol temiz taranmış olmalıdır.');
            if(!$r->supersedes_id && $r->valid_until!==\Carbon\Carbon::parse($r->signed_on)->addYears(3)->toDateString()) throw new DomainError('PROTOKOL_SURESI','İlk protokol imza tarihinden itibaren üç yıl geçerlidir.');
        }
        if ($type==='capacity_requests' && $to==='onaylandi') {
            $offer=Records::query('offers')->lockForUpdate()->findOrFail($r->offer_id);
            $offer->capacity=$r->requested_capacity; $offer->save();
        }
        if ($type==='placements') {
            if ($to==='onaylandi') {
                if ($r->run_id) app(Matching::class)->assertFresh(Records::get('matching_runs',$r->run_id));
                $why=app(Eligibility::class)->pair(Records::get('applications',$r->application_id),Records::get('offers',$r->offer_id),true)['reasons'];
                if ($why) throw new DomainError('YERLESTIRME_UYGUN_DEGIL',implode(' ',$why));
                $offer=Records::get('offers',$r->offer_id);
                if (Records::query('placements')->where('term_id',$r->term_id)->whereIn('state',Eligibility::ACTIVE)->whereIn('offer_id',Records::query('offers')->where('trainer_id',$offer->trainer_id)->select('id'))->count()>=5) throw new DomainError('EGITICI_SINIRI','Eğitici başına beş öğrenci sınırı dolu.');
            }
            if($to==='ilan_edildi') throw new DomainError('ILAN_SURECI','Yerleştirme ilanı için yayınlama uç noktasını kullanın.');
            if(in_array($to,['baslamaya_hazir','basladi'])) { $missing=app(Eligibility::class)->ready($r); if($missing) throw new DomainError('BASLAMA_EKSIK','Başlama koşulları eksik: '.implode(', ',$missing)); }
            if($to==='tamamlandi') { $summary=app(Education::class)->attendanceSummary($r); if(!$summary['complete']) throw new DomainError('EKSIK_DEVAM','Eğitim kapanışından önce devam kayıtları tamamlanmalıdır.'); }
        }
        if ($type==='attendance' && $to==='gonderildi') { app(ScopeAccess::class)->assertAction('egitici',$r); $r->trainer_approved=true; }
        if ($type==='attendance' && $to==='onaylandi') { if(!$r->trainer_approved) throw new DomainError('EGITICI_ONAYI_EKSIK','Devam kaydı eğitici tarafından gönderilip onaylanmalıdır.'); $r->adviser_reviewed=true; }
        if($type==='attendance' && $to==='onaylandi') {
            Records::query('placements')->where('id',$r->placement_id)->lockForUpdate()->firstOrFail();
            $day=\Carbon\CarbonImmutable::parse($r->date);$ids=Records::query('placements')->where('student_id',$r->student_id)->where('term_id',$r->term_id)->pluck('id');
            if(Records::query('attendance')->whereIn('placement_id',$ids)->where('id','!=',$r->id)->where('state','onaylandi')->whereBetween('date',[$day->startOfWeek()->toDateString(),$day->endOfWeek()->toDateString()])->count()>=5)throw new DomainError('HAFTALIK_CALISMA','Eğitim haftada beş iş günü üzerinden yürütülür.');
        }
        if ($type==='learning_plans' && $to==='onaylandi') {
            $p=Records::get('placements',$r->placement_id); $t=Records::get('academic_terms',$r->term_id);
            if($r->revision===1 && \Carbon\Carbon::parse($r->prepared_on??$r->created_at)->greaterThan(app(BusinessCalendar::class)->deadline($p->starts_on,10,$t))) throw new DomainError('PLAN_GECIKTI','İlk öğrenme planı on iş günü içinde hazırlanmalıdır.');
            if(!empty($input['adviser_opinion_document_id'])) { $doc=Records::get('documents',$input['adviser_opinion_document_id']);Gate::authorize('record-view',$doc);if(!app(Eligibility::class)->cleanDocument($doc->id))throw new DomainError('BELGE_EKSIK','Danışman uygun görüşü temiz taranmış olmalıdır.');$r->adviser_opinion_document_id=$doc->id; }
            if($r->revision>1 && (empty($r->revision_reason)||!app(Eligibility::class)->cleanDocument($r->adviser_opinion_document_id))) throw new DomainError('PLAN_REVIZYONU','Plan revizyonu danışman uygun görüşü ve gerekçe gerektirir.');
            if(!Records::query('plan_outcomes')->where('plan_id',$r->id)->exists()) throw new DomainError('PLAN_EKSIK','Öğrenme planında kazanım–faaliyet–kanıt eşleştirmesi yok.');
            if(Records::query('weekly_plan_tasks')->where('plan_id',$r->id)->distinct()->count('week_no')<15) throw new DomainError('PLAN_EKSIK','Öğrenme planı 15 haftanın tamamını kapsamalıdır.');
            $tasks=Records::query('weekly_plan_tasks')->where('plan_id',$r->id)->get();
            if(abs($tasks->sum('expected_hours')-$r->workplace_hours)>0.001)throw new DomainError('PLAN_IS_YUKU','Haftalık işyeri görevlerinin toplamı 600 saat olmalıdır.');
            $outcomes=Records::query('plan_outcomes')->where('plan_id',$r->id)->pluck('outcome_id');
            foreach($tasks as $task)if(!$outcomes->contains($task->outcome_id))throw new DomainError('PLAN_KAZANIMI','Haftalık görev kazanımı plan eşleştirmesinde bulunmalıdır.');
        }
        if ($type==='rubric_evaluations') {
            $expectedRole=$r->source==='is_yeri'?'egitici':'akademik_danisman';
            if($assignment->role!==$expectedRole) throw new DomainError('FORBIDDEN_SCOPE','Değerlendirme kaynağı için görev yetkiniz yok.',403);
            if($to==='onaylandi' && $r->source==='is_yeri' && count($r->workplace_criteria_scores??[])!==12) throw new DomainError('EK3_EKSIK','EK-3 üzerindeki on iki ölçüt puanı gereklidir.',422);
        }
        if($type==='self_assessments' && $to==='onaylandi') {
            $review=Validator::make($input,['portfolio_integrity'=>'required|in:yeterli,kismen_yeterli,yetersiz','evidence_consistency'=>'required|in:tutarli,kismen_tutarli,tutarsiz','adviser_feedback'=>'required|string|max:20000'])->validate();$r->fill($review);
        }
        if ($type==='weekly_reports' && $to==='iade' && empty($input['reason'])) throw new DomainError('GEREKCE_GEREKLI','Düzeltme istemi yazılı geri bildirim gerektirir.',422);
        if ($type==='weekly_reports' && in_array($assignment->role,['egitici','akademik_danisman'])) {
            $field=$assignment->role==='egitici'?'trainer_review':'adviser_review';
            if(!empty($input['reason'])) $r->$field=$input['reason'];
        }
        if ($type==='weekly_reports' && $to==='gonderildi' && !Records::query('report_outcomes')->where('report_id',$r->id)->exists()) throw new DomainError('KAZANIM_EKSIK','Haftalık rapor en az bir öğrenme kazanımıyla ilişkilendirilmelidir.');
        if($type==='training_file_deliveries' && $to==='onaylandi') {
            $reports=Records::query('weekly_reports')->where('student_id',$r->student_id)->where('term_id',$r->term_id)->orderByDesc('revision')->get()->unique('week_no');
            if($reports->where('state','danisman_onayi')->count()<15)throw new DomainError('DOSYA_ICERIGI','Eğitim dosyası on beş haftanın eğitici ve danışman onaylı raporlarını içermelidir.');
            if(!Records::query('portfolio_evidence')->where('student_id',$r->student_id)->where('term_id',$r->term_id)->where('state','onaylandi')->exists() || !Records::query('self_assessments')->where('student_id',$r->student_id)->where('term_id',$r->term_id)->where('phase','donem_sonu')->where('state','onaylandi')->exists())throw new DomainError('DOSYA_ICERIGI','Eğitim dosyası kazanım kanıtı ve danışman onaylı dönem sonu EK-17 içermelidir.');
        }
        if ($type==='company_performance' && $to==='onaylandi') {
            $company=Records::get('companies',$r->company_id);
            if($r->authorization_years===0) { $company->state='askida'; $company->save(); }
        }
        if ($type==='change_requests' && $to==='uygulandi') $this->applyChange($r,$input);
        if ($type==='appeals' && in_array($to,['kabul','ret'])) $r->decision_id=Records::query('decisions')->where('target_type','appeals')->where('target_id',$r->id)->latest()->first()?->id;
        if ($type==='nonconformities' && $to==='karar_verildi') {
            $r->authorized_failure=(bool)($input['authorized_failure']??false);
            $r->decision_id=Records::query('decisions')->where('target_type','nonconformities')->where('target_id',$r->id)->latest()->first()?->id;
        }
        if ($type==='appeals' && in_array($to,['uygulandi','ret'])) {
            $target=Records::query($r->target_type)->lockForUpdate()->findOrFail($r->target_id);
            $open=Records::query('appeals')->where('target_type',$r->target_type)->where('target_id',$r->target_id)->where('id','!=',$r->id)->whereNotIn('state',['uygulandi','ret'])->exists();
            $target->legal_hold=$open;$target->save();
            foreach(Records::query('documents')->where('target_type',$r->target_type)->where('target_id',$r->target_id)->get() as $doc){$doc->legal_hold=$open;$doc->save();}
        }
        if ($type==='recognition_requests' && $to==='onaylandi') {
            $board=$r->board_decision_id?Records::get('decisions',$r->board_decision_id):null;
            if(!$board || $board->stage!=='kurul' || $board->outcome!=='onay' || $board->target_type!=='recognition_requests' || $board->target_id!==$r->id)throw new DomainError('KURUL_KARARI','Önceki öğrenmenin tanınması için ilgili kayıt hakkında MYO Yönetim Kurulu kararı gerekir.');
        }
        if ($type==='nonconformities' && $to==='kapandi' && !app(Eligibility::class)->cleanDocument($r->directorate_document_id))throw new DomainError('MUDURLUK_ONAYI','EK-6 kapanışında Müdürlük onay belgesi gerekir.');
        if($type==='improvement_actions' && $to==='kapandi' && (empty($r->effect_evidence)||$r->remeasured_value===null))throw new DomainError('ETKI_KANITI','Eylem kapanışı etki kanıtı ve yeniden ölçülen gösterge değeri gerektirir.',422);
        if($type==='success_results' && $to==='ilan_edildi')throw new DomainError('ILAN_SURECI','Değerlendirme ilanı için yayınlama uç noktasını kullanın.');
        if($type==='annual_reports' && $to==='onaylandi' && !$r->board_document_id)throw new DomainError('KURUL_KARARI','Yıllık rapor için MYO Kurulu kabul belgesi gereklidir.');
        if($type==='annual_reports' && $to==='onaylandi') $this->validateAnnual($r);
        $r->state=$to; $r->save();
        app(Outbox::class)->record($r,'durum_degisti','Kayıt durumu güncellendi: '.config('portal.values.'.$to,$to));
        return $r->refresh();
    }

    public function rulesFor(string $type,string $to): array
    {
        $roles=config("domain.$type.write",[]);
        $decision=false;
        $roleList=implode(' ',$roles);
        if (in_array($to,['onaylandi','reddedildi','karar_verildi','kabul','ret','feshedildi','askida'])) {
            $roleList=match($type) {
                'applications'=>'bolum_komisyon program_baskani',
                'weekly_reports','inspections','online_records','attendance','training_file_deliveries','portfolio_evidence','self_assessments','presentations'=>'akademik_danisman',
                'rubric_evaluations'=>'egitici akademik_danisman',
                'ohs_records'=>'akademik_danisman bolum_komisyon',
                'success_results'=>'bolum_komisyon',
                'interviews'=>'isletme_yetkilisi',
                'trainer_qualifications','rubric_versions','learning_plans','matching_policies'=>'bolum_komisyon',
                'insurance_records','financial_policies','payroll','fund_contributions'=>'belge_gorevlisi',
                default=>'mue_komisyon',
            };
            $decision=in_array($type,['company_assessments','offers','capacity_requests','matching_policies','placements','appeals','change_requests','company_performance','nonconformities']);
        }
        if ($to==='ilan_edildi') $roleList='mudur';
        if ($type==='offers' && $to==='dogrulandi') $roleList='koordinator';
        if ($type==='protocols' && in_array($to,['aktif','feshedildi','askida'])) { $roleList='mudur'; $decision=$to!=='aktif'; }
        if ($type==='companies' && in_array($to,['aktif','askida','pasif'])) { $roleList='mue_komisyon'; $decision=true; }
        if ($type==='weekly_reports' && $to==='egitici_onayi') $roleList='egitici';
        if ($type==='weekly_reports' && $to==='danisman_onayi') $roleList='akademik_danisman';
        if ($type==='applications' && in_array($to,['uygun','eksik','uygun_degil'])) $roleList='bolum_komisyon program_baskani';
        if ($type==='placements' && $to==='bolum_incelemesi') $roleList='bolum_komisyon';
        if ($type==='placements' && in_array($to,['baslamaya_hazir','basladi','tamamlandi'])) $roleList='bolum_komisyon';
        if ($type==='weekly_reports' && $to==='iade') $roleList='egitici akademik_danisman';
        if($to==='iade' && $type!=='weekly_reports') $roleList=match($type){'learning_plans','trainer_qualifications','rubric_versions','matching_policies'=>'bolum_komisyon','applications'=>'bolum_komisyon program_baskani','attendance','inspections','online_records','portfolio_evidence','self_assessments','training_file_deliveries','presentations','ohs_records'=>'akademik_danisman bolum_komisyon','insurance_records','payroll','fund_contributions','financial_policies'=>'belge_gorevlisi',default=>'mue_komisyon'};
        if ($to==='inceleniyor') $roleList=match($type){'applications'=>'bolum_komisyon program_baskani','learning_plans','trainer_qualifications','rubric_versions','matching_policies'=>'bolum_komisyon','attendance','inspections','online_records','portfolio_evidence','self_assessments','training_file_deliveries','presentations','ohs_records'=>'akademik_danisman bolum_komisyon','insurance_records','payroll','fund_contributions','financial_policies'=>'belge_gorevlisi','interviews'=>'isletme_yetkilisi',default=>'mue_komisyon'};
        if ($type==='rubric_evaluations') $roleList='egitici akademik_danisman';
        if ($type==='appeals') $roleList='mue_komisyon';
        if ($type==='change_requests' && in_array($to,['inceleniyor','uygulandi'])) $roleList='mue_komisyon';
        if ($type==='nonconformities' && $to==='kapandi') $roleList='mue_komisyon';
        return ['roles'=>explode(' ',$roleList),'decision'=>$decision];
    }

    public function decision(DomainRecord $r,array $input,DomainRecord $assignment): DomainRecord
    {
        $d=Validator::make($input,['decision_no'=>'required|string|max:250','decision_on'=>'required|date','document_id'=>'required|uuid','reason'=>'required|string|max:20000','meeting_date'=>'required|date','members_count'=>'required|integer|min:1','attendees_count'=>'required|integer|min:1','votes_for'=>'required|integer|min:0','votes_against'=>'required|integer|min:0','chair_vote_for'=>'required|boolean','outcome'=>'required|in:onay,ret,iade,askiya_alma,fesih'])->validate();
        if($d['attendees_count']<floor($d['members_count']/2)+1 || $d['attendees_count']>$d['members_count'] || $d['votes_for']+$d['votes_against']!==$d['attendees_count']) throw new DomainError('KARAR_TOPLANTISI','Toplantı katılımı ve oy bilgileri geçersiz.',422);
        if($d['votes_for']<$d['votes_against'] || ($d['votes_for']===$d['votes_against']&&!$d['chair_vote_for'])) throw new DomainError('KARAR_COGUNLUGU','Kayıtlı karar için çoğunluk veya başkanın eşitlik oyu gerekir.',422);
        $doc=Records::get('documents',$d['document_id']); Gate::authorize('record-view',$doc);
        if($doc->institution_id!==$r->institution_id || !app(Eligibility::class)->cleanDocument($doc->id)) throw new DomainError('KARAR_BELGESI','Karar belgesi geçersiz.');
        $stage=match($assignment->role){'bolum_komisyon'=>'bolum','mudur'=>'mudurluk',default=>'mue'};
        if(($input['stage']??null)==='kurul' && $assignment->role==='mudur')$stage='kurul';
        return Records::create('decisions',array_merge(Records::scope($r),$d,['target_type'=>$r->getTable(),'target_id'=>$r->id,'stage'=>$stage]));
    }

    private function applyChange(DomainRecord $request,array $input): void
    {
        app(ScopeAccess::class)->assertAction('mue_komisyon',$request);
        if(empty($request->directorate_document_id) || !app(Eligibility::class)->cleanDocument($request->directorate_document_id)) throw new DomainError('MUDURLUK_ONAYI','İşletme değişikliğinde Müdürlük onayı gereklidir.');
        if(!$request->credit_reviewed_by)throw new DomainError('DANISMAN_MAHSUP','Önceki süre ve kazanımların akademik danışman tarafından değerlendirilmesi gerekir.');
        $old=Records::query('placements')->lockForUpdate()->findOrFail($request->placement_id); $offer=Records::get('offers',$request->new_offer_id);
        if(!in_array($old->state,['basladi','askida']))throw new DomainError('DEGISIKLIK_DURUMU','Dönem içi değişiklik başlamış veya askıya alınmış eğitim için uygulanabilir.');
        $check=app(Eligibility::class)->pair(Records::get('applications',$old->application_id),$offer,true);
        if(!$check['eligible']) throw new DomainError('DEGISIKLIK_UYGUN_DEGIL',implode(' ',$check['reasons']));
        $changeOn=$input['change_on']??null; Validator::make(['change_on'=>$changeOn],['change_on'=>'required|date|after:'.$old->starts_on.'|before_or_equal:'.$old->ends_on])->validate();
        $old->ends_on=\Carbon\Carbon::parse($changeOn)->subDay()->toDateString(); $old->state='degistirildi'; $old->save();
        $new=Records::create('placements',array_merge(Records::scope($old),['placement_id'=>null,'company_id'=>$offer->company_id,'application_id'=>$old->application_id,'offer_id'=>$offer->id,'run_id'=>null,'starts_on'=>$changeOn,'ends_on'=>Records::get('academic_terms',$old->term_id)->ends_on,'previous_placement_id'=>$old->id,'credited_minutes'=>$request->credited_minutes,'credited_outcomes'=>$request->credited_outcomes,'reason'=>$request->reason,'state'=>'onaylandi']));
        $decision=Records::query('decisions')->where('target_type','change_requests')->where('target_id',$request->id)->where('stage','mue')->where('outcome','onay')->latest()->first();
        if(!$decision)throw new DomainError('KARAR_EKSIK','İşletme değişikliği için nihai MUE Komisyonu kararı gerekir.');
        Records::create('decisions',array_merge(Records::scope($new),$decision->only(array_keys(config('domain.decisions.fields'))),['target_type'=>'placements','target_id'=>$new->id]));
    }
    private function validateAnnual(DomainRecord $r): void
    {
        $board=Records::query('decisions')->where('target_type','annual_reports')->where('target_id',$r->id)->where('stage','kurul')->orderByDesc('created_at')->orderByDesc('id')->first();
        if(!$board || $board->outcome!=='onay' || $board->document_id!==$r->board_document_id)throw new DomainError('KURUL_KARARI','Yıllık rapor için ilgili rapora ait MYO Kurulu kabul kararı gerekir.');
        $seen=[];
        foreach($r->outcome_attainment as $outcome) {
            $output=Records::get('program_outputs',$outcome['program_output_id']);$seen[]=$output->id;
            if(($outcome['students_above_60_percent']??0)<$output->target_percent && !Records::query('improvement_actions')->where('annual_report_id',$r->id)->where('program_output_id',$output->id)->exists()) throw new DomainError('IYILESTIRME_EKSIK','Hedefe ulaşmayan her program çıktısı için düzeltici faaliyet gerekir.');
        }
        $outputs=Records::query('program_outputs')->where('institution_id',$r->institution_id);if($r->program_id)$outputs->where('program_id',$r->program_id);
        if($outputs->whereNotIn('id',$seen)->exists())throw new DomainError('CIKTI_ANALIZI_EKSIK','Yıllık rapor ilgili program çıktılarının tamamını kapsamalıdır.');
    }
}
