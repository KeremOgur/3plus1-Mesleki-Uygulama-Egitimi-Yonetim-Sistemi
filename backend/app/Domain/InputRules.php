<?php
namespace App\Domain;
use App\Models\DomainRecord;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;

class InputRules
{
    public function validate(string $type,array $input,DomainRecord $assignment,?DomainRecord $existing=null): array
    {
        $spec=config("domain.$type"); abort_unless($spec,404,'Kayıt türü bulunamadı.'); $rules=[];
        if($type==='matching_policies') $input+=['preference_weight'=>0.75,'transport_weight'=>0.25];
        foreach ($spec['fields'] as $key=>$f) $rules[$key]=$f['rules'];
        $contexts=['institution_id','program_id','term_id','company_id','student_id','placement_id'];
        foreach ($contexts as $c) $rules[$c]='nullable|uuid';
        $input=Validator::make($input,$rules)->validate();
        $context=$existing ? Records::scope($existing) : array_filter(array_intersect_key($input,array_flip($contexts)),fn($v)=>$v!==null);
        foreach ($spec['fields'] as $key=>$f) {
            if (!$f['ref'] || empty($input[$key]) || $f['ref']==='users') continue;
            $parent=Records::get($f['ref'],$input[$key]);
            if ($type!=='interviews' || $f['ref']!=='applications') Gate::authorize('record-view',$parent);
            foreach (Records::scope($parent) as $c=>$v) if ($v && $c!=='placement_id') {
                if (isset($context[$c]) && $context[$c]!==$v) throw new DomainError('KAPSAM_UYUSMAZLIGI','İlişkili kayıtların kapsamları uyuşmuyor.',422,[$c=>['İlişki kapsamı geçersiz.']]);
                $context[$c]=$v;
            }
        }
        foreach ($contexts as $c) if (!empty($input[$c])) {
            $parentType=match($c){'institution_id'=>'institutions','program_id'=>'programs','term_id'=>'academic_terms','company_id'=>'companies','student_id'=>'students','placement_id'=>'placements'};
            $parent=Records::get($parentType,$input[$c]);
            if ($c!=='institution_id' && !($c==='student_id' && in_array($assignment->role,['isletme_yetkilisi','egitici']) && in_array($type,['interviews','payroll','fund_contributions','trainer_assignments','incidents','nonconformities']))) Gate::authorize('record-view',$parent);
            if ($c==='placement_id') $context=array_merge($context,array_filter(Records::scope($parent),fn($v)=>$v!==null));
            if ($parentType!=='institutions') $context['institution_id']=$parent->institution_id;
        }
        $context['institution_id'] ??= $assignment->institution_id;
        if ($existing && $context!==Records::scope($existing)) throw new DomainError('KAPSAM_SABIT','Kayıt kapsamı değiştirilemez.',422);
        foreach ($spec['required_context'] as $c) if (empty($context[$c])) throw new DomainError('KAPSAM_GEREKLI','Gerekli kayıt kapsamı eksik: '.$c,422);
        if (isset($context['student_id'])) {
            $student=Records::get('students',$context['student_id']);
            foreach (['program_id','institution_id'] as $c) { if (isset($context[$c]) && $context[$c]!==$student->$c) throw new DomainError('KAPSAM_UYUSMAZLIGI','Öğrenci program kapsamı uyuşmuyor.',422); $context[$c]=$student->$c; }
        }
        $input=array_merge($input,$context);
        $candidate=DomainRecord::for($type)->fill($input); if($existing)$candidate->id=$existing->id;
        Gate::authorize('record-write',$candidate);
        $this->business($type,$input,$assignment,$existing);
        if($type==='institutions')foreach($contexts as $c)unset($input[$c]);
        return $input;
    }

    private function business(string $type,array &$d,DomainRecord $a,?DomainRecord $old): void
    {
        $fail=fn($message)=>throw new DomainError('VALIDATION_ERROR',$message,422);
        if ($type==='role_assignments') {
            if ($a->role==='sistem_yoneticisi' && $d['role']!=='sistem_yoneticisi') $fail('Akademik görevler Müdürlüğün görevlendirmesiyle verilir.');
            if ($a->role==='mudur' && $d['role']==='sistem_yoneticisi') $fail('Teknik yöneticilik teknik hesap sürecinden verilir.');
            if (in_array($d['role'],['bolum_komisyon','program_baskani']) && empty($d['program_id'])) $fail('Program görevi için program kapsamı gereklidir.');
            if (in_array($d['role'],['isletme_yetkilisi','egitici']) && empty($d['company_id'])) $fail('İşletme görevi için işletme kapsamı gereklidir.');
        }
        foreach (['plan_outcomes'=>'plan_id','weekly_plan_tasks'=>'plan_id','report_outcomes'=>'report_id'] as $child=>$key) if($type===$child) {
            $parent=Records::get($child==='report_outcomes'?'weekly_reports':'learning_plans',$d[$key]);
            if(!in_array($parent->state,['taslak','iade'])) $fail('Onay sürecindeki kaydın alt satırları değiştirilemez; yeni sürüm oluşturun.');
        }
        if ($type==='academic_terms') {
            foreach($d['holiday_dates'] as $date) if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$fail('Tatil tarihleri yıl-ay-gün biçiminde olmalıdır.');
            foreach($d['letter_grade_rules']??[] as $row)if(!isset($row['minimum'],$row['letter']) || !is_numeric($row['minimum']) || $row['minimum']<0 || $row['minimum']>100 || !is_string($row['letter']))$fail('Harf notu tablosu geçersiz.');
            if($d['letter_grade_rules']??null)usort($d['letter_grade_rules'],fn($x,$y)=>$y['minimum']<=>$x['minimum']);
        }
        if ($type==='companies' && $d['organization_type']==='ozel' && empty($d['tax_no']) && empty($d['mersis_no'])) $fail('Özel işletmenin vergi veya MERSİS kimliği gerekir.');
        if($type==='trainer_qualifications') {
            foreach($d['education_records'] as $row)if(!is_array($row)||empty($row['institution'])||empty($row['field'])||empty($row['degree']))$fail('EK-19 öğrenim satırında kurum, alan ve öğrenim düzeyi gerekir.');
            foreach($d['experience_records'] as $row)if(!is_array($row)||empty($row['institution'])||empty($row['duty'])||empty($row['started_on'])||empty($row['duration']))$fail('EK-19 deneyim satırında kurum, görev, başlangıç ve süre gerekir.');
        }
        if ($type==='company_sites' && $d['capacity_type']==='ortak' && $d['total_capacity']===null) $fail('Ortak şube kapasitesi belirtilmelidir.');
        if ($type==='term_programs') {
            if ($d['active_student_count']<15 && ($d['semester']!==4 || $d['grouping_enabled'])) $fail('15 öğrencinin altında eğitim dördüncü yarıyılda yürütülür.');
            if(\Carbon\Carbon::parse($d['preference_deadline'])->lessThanOrEqualTo($d['preference_opens_at']))$fail('Tercih kapanışı açılıştan sonra olmalıdır.');
        }
        if ($type==='interviews' && $d['result']==='ret' && (empty($d['reason']) || empty($d['reason_code']))) $fail('İşletme reddinde gerekçe kodu ve açıklama zorunludur.');
        if($type==='trainer_assignments') { $p=Records::get('placements',$d['placement_id']);$o=Records::get('offers',$p->offer_id);if($d['trainer_id']!==$o->trainer_id)$fail('Görevlendirilen eğitici onaylı dönem teklifinin eğiticisi olmalıdır.'); }
        if($type==='recognition_requests' && $a->role==='ogrenci')$d['board_decision_id']=null;
        if ($type==='nonconformities') $d['authorized_failure']=false;
        if ($type==='weekly_reports' && $a->role==='ogrenci') { $d['trainer_review']=$old?->trainer_review; $d['adviser_review']=$old?->adviser_review; }
        if ($type==='declarations' && $d['text_version']!==config('forms.version')) $fail('Taahhütname metninin yürürlükteki sürümü kullanılmalıdır.');
        if ($type==='change_requests') { $d['credited_minutes']=$old?->credited_minutes??0;$d['credited_outcomes']=$old?->credited_outcomes??[];$d['credit_reviewed_by']=$old?->credit_reviewed_by;$d['directorate_document_id']=$old?->directorate_document_id; }
        if ($type==='nonconformities') $d['directorate_document_id']=$old?->directorate_document_id;
        if($type==='improvement_actions') {
            if($a->role==='akademik_danisman' && empty($d['inspection_id']))$fail('Danışman iyileştirme eylemi atanmış öğrencinin denetimine bağlı olmalıdır.');
            if(!Records::query('role_assignments')->where('user_id',$d['owner_user_id'])->where('institution_id',$d['institution_id'])->where('state','aktif')->where('valid_from','<=',now())->where(fn($q)=>$q->whereNull('valid_until')->orWhere('valid_until','>=',now()))->exists())$fail('Eylem sorumlusu kurumda aktif görevi bulunan bir kullanıcı olmalıdır.');
        }
        if ($type==='matching_policies') {
            if (abs($d['preference_weight']+$d['transport_weight']-1)>0.00001) $fail('Eşleştirme ağırlıklarının toplamı 1 olmalıdır.');
            $previous=-1;$last=count($d['transport_rules'])-1;
            if($last<0)$fail('Ulaşım puan tablosu boş olamaz.');
            foreach(array_values($d['transport_rules']) as $i=>$r) {
                if (!isset($r['score']) || !is_numeric($r['score']) || $r['score']<0 || $r['score']>100 || (isset($r['max_minutes']) && (!is_numeric($r['max_minutes']) || $r['max_minutes']<0))) $fail('Ulaşım puan tablosu geçersiz.');
                if(isset($r['max_minutes'])) { if($r['max_minutes']<=$previous)$fail('Ulaşım süreleri artan ve çakışmayan sıra ile tanımlanmalıdır.'); $previous=$r['max_minutes']; }
                elseif($i!==$last)$fail('Üst sınırsız ulaşım kuralı son satır olmalıdır.');
            }
        }
        if ($type==='offers' && $old && (int)$old->capacity!==(int)$d['capacity']) $fail('Kontenjan değişikliği için kapasite talep ve onay sürecini kullanın.');
        if ($type==='applications') {
            if ($old && $old->state!=='taslak' && $old->state!=='eksik') $fail('Gönderilmiş başvuru bu işlemle değiştirilemez.');
            $d['eligibility']=null; $d['eligibility_reason']=null;
            $s=Records::get('students',$d['student_id']); $d['gpa_snapshot']=$s->gpa; $d['gpa_source']=$s->academic_source; $d['gpa_recorded_at']=now();
        }
        if($type==='placements' && !$old) {
            $term=Records::get('academic_terms',$d['term_id']);if($d['starts_on']!==$term->starts_on || $d['ends_on']!==$term->ends_on)$fail('İlk yerleştirme eğitim döneminin tamamını kapsamalıdır.');
            $d['credited_minutes']=0;$d['credited_outcomes']=[];$d['previous_placement_id']=null;$d['online_decision_id']=null;
        }
        if ($type==='attendance') {
            $d['trainer_approved']=false; $d['adviser_reviewed']=false;
            $p=Records::get('placements',$d['placement_id']); if ($d['date']<$p->starts_on || $d['date']>$p->ends_on) $fail('Devam tarihi eğitim aralığında olmalıdır.');
            if ($d['mark']==='V' && $d['attended_minutes']!==$d['planned_minutes']) $fail('Tam devam işareti planlanan sürenin tamamını gerektirir.');
            if($d['mark']!=='V' && $d['attended_minutes']<$d['planned_minutes'] && empty($d['absence_reason']))$fail('Devamsızlık, izin veya rapor açıklaması gereklidir.');
        }
        if ($type==='weekly_reports') {
            $p=Records::get('placements',$d['placement_id']);
            if($d['week_start']<$p->starts_on || $d['week_end']>$p->ends_on || \Carbon\Carbon::parse($d['week_start'])->diffInDays($d['week_end'])>6)$fail('Haftalık rapor tarihleri eğitim aralığında ve en fazla yedi gün olmalıdır.');
        }
        if($type==='portfolio_evidence' && in_array($d['evidence_type'],['gorsel','teknik']))$d['protected_material']=true;
        if ($type==='portfolio_evidence' && $d['protected_material']) {
            if (empty($d['company_permission_id']) || !app(Eligibility::class)->cleanDocument($d['company_permission_id'])) $fail('Gizli görsel veya teknik kanıt için işletmenin yazılı izni gerekir.');
            $permission=Records::get('documents',$d['company_permission_id']);
            if ($permission->company_id!==$d['company_id']) $fail('Paylaşım izni aynı işletmeye ait olmalıdır.');
        }
        if ($type==='self_assessments') {
            if(count($d['transferable_competencies'])!==10)$fail('EK-17 için on aktarılabilir yetkinlik değerlendirmesi gerekir.');
            foreach($d['transferable_competencies'] as $row)if(!is_array($row)||!isset($row['value'])||!is_numeric($row['value'])||$row['value']<1||$row['value']>4)$fail('EK-17 yetkinlik düzeyleri 1–4 aralığında olmalıdır.');
            foreach($d['reflection_answers'] as $answer)if(!is_string($answer)||trim($answer)==='')$fail('EK-17 yansıtıcı değerlendirme soruları yanıtlanmalıdır.');
            foreach($d['portfolio_checklist'] as $row)if(!is_array($row)||!isset($row['value'])||!is_bool($row['value']))$fail('EK-17 portfolyo kontrolü her satır için var/yok bilgisi gerektirir.');
            $d['portfolio_integrity']=$old?->portfolio_integrity;$d['evidence_consistency']=$old?->evidence_consistency;$d['adviser_feedback']=$old?->adviser_feedback;
            $p=Records::get('placements',$d['placement_id']); $day=today()->toDateString();
            if ($d['phase']==='donem_basi' && ($day<$p->starts_on || $day>\Carbon\Carbon::parse($p->starts_on)->addDays(13)->toDateString())) $fail('Dönem başı öz değerlendirme ilk iki haftada yapılır.');
            if ($d['phase']==='donem_sonu' && ($day<\Carbon\Carbon::parse($p->ends_on)->subDays(6)->toDateString() || $day>$p->ends_on)) $fail('Dönem sonu öz değerlendirme son haftada yapılır.');
        }
        if ($type==='inspections' && $d['mode']==='cevrim_ici') {
            $p=Records::get('placements',$d['placement_id']);
            if (!$p->online_decision_id || empty($d['online_record_id'])) $fail('Çevrim içi denetim komisyon kararı ve EK-13 kaydı gerektirir.');
            $decision=Records::get('decisions',$p->online_decision_id);$online=Records::get('online_records',$d['online_record_id']);
            if($decision->stage!=='mue' || $decision->outcome!=='onay' || $decision->target_type!=='placements' || $decision->target_id!==$p->id || $online->decision_id!==$decision->id)$fail('Çevrim içi izin ve görüşme aynı yerleştirmeye ait olmalıdır.');
        }
        if($type==='inspections') { $p=Records::get('placements',$d['placement_id']);$date=\Carbon\Carbon::parse($d['inspected_at'])->toDateString();if($date<$p->starts_on || $date>$p->ends_on)$fail('Denetim tarihi eğitim aralığında olmalıdır.'); }
        if ($type==='rubric_versions') {
            foreach ($d['levels'] as $l) if (empty($l['name']) || empty($l['behavior'])) $fail('Rubrik düzeyleri gözlenebilir davranış tanımı içermelidir.');
        }
        if($type==='academic_terms' && $old && ($d['starts_on']!==$old->starts_on || $d['ends_on']!==$old->ends_on) && Records::query('placements')->where('term_id',$old->id)->whereIn('state',Eligibility::ACTIVE)->exists())$fail('Etkin yerleştirmeler varken dönem eğitim tarihleri değiştirilemez; yetkili değişiklik sürecini kullanın.');
        if ($type==='rubric_evaluations') {
            if (($d['source']==='is_yeri' && $a->role!=='egitici') || ($d['source']!=='is_yeri' && $a->role!=='akademik_danisman')) $fail('Bu değerlendirme kaynağı için görev yetkiniz yok.');
            $rubric=Records::get('rubric_versions',$d['rubric_id']); if ($rubric->state!=='onaylandi') $fail('Onaylı rubrik sürümü gereklidir.');
            foreach($d['criterion_values'] as $v) if (!is_numeric($v) || $v<0 || $v>100) $fail('Rubrik kriter puanları 0–100 aralığında olmalıdır.');
            $d['score']=array_sum($d['criterion_values'])/count($d['criterion_values']);
            foreach($d['workplace_criteria_scores']??[] as $score)if(!is_numeric($score)||$score<0||$score>100)$fail('EK-3 ölçüt puanları 0–100 aralığında olmalıdır.');
        }
        foreach (['inspections'=>'criteria_scores','presentations'=>'criteria_scores','feedback'=>'items'] as $t=>$field) if ($type===$t) foreach($d[$field] as $v) if (!is_numeric($v) || $v<($type==='feedback'?1:0) || $v>($type==='feedback'?5:100)) $fail('Form puanları ölçeğin dışında.');
        if ($type==='plan_outcomes') {
            if (count(array_unique($d['evidence_sources']))<2) $fail('Her kazanım için iki bağımsız kanıt kaynağı gerekir.');
            foreach ($d['planned_weeks'] as $w) if (!is_int($w) || $w<1 || $w>15) $fail('Plan haftaları 1–15 aralığında olmalıdır.');
        }
        if ($type==='weekly_plan_tasks') {
            $risk=Records::get('risk_items',$d['risk_item_id']); $score=$risk->residual_probability*$risk->residual_severity;
            $plan=Records::get('risk_plans',$risk->risk_plan_id);if($plan->state!=='onaylandi' || $plan->renewal_on<today()->toDateString())$fail('Öğrenci görevi için güncel ve onaylı risk planı gerekir.');
            $placement=Records::get('placements',$d['placement_id']);if($plan->site_id!==Records::get('offers',$placement->offer_id)->site_id)$fail('Görevin risk kaydı öğrencinin eğitim aldığı şubeye ait olmalıdır.');
            if ($score>=15 || ($score>=8 && (!$risk->mitigation_completed || !$risk->direct_supervision))) $fail('Risk koşulları öğrenciye görev verilmesine izin vermiyor.');
        }
        if ($type==='payroll') {
            $policy=Records::get('financial_policies',$d['financial_policy_id']); $company=Records::get('companies',$d['company_id']);
            if ($policy->state!=='onaylandi' || $d['payment_on']<$policy->valid_from || $d['payment_on']>$policy->valid_until) $fail('Geçerli ve onaylı mali politika gerekir.');
            $d['minimum_due']=round($policy->net_minimum_wage*$policy->minimum_wage_rate,2);
            $eligible=$company->organization_type==='ozel' && $policy->contribution_extension_confirmed;
            $d['state_contribution']=$eligible?round($d['minimum_due']*($d['personnel_count']<20?$policy->contribution_under20:$policy->contribution_20plus),2):0;
            $d['employer_share']=max(0,$d['gross_paid']-$d['state_contribution']);
            $student=Records::get('students',$d['student_id']); if ($d['student_iban']!==$student->iban) $fail('Ödeme öğrencinin kendi kayıtlı hesabına yapılmalıdır.');
        }
        if ($type==='fund_contributions') { $pay=Records::get('payroll',$d['payroll_id']); $d['total_claim']=$pay->state_contribution*$d['claimed_months']; }
        if ($type==='company_performance') {
            $max=[20,15,20,10,15,5,5,10]; foreach(array_values($d['criterion_scores']) as $i=>$score) if (!is_numeric($score) || $score<0 || $score>$max[$i]) $fail('İşletme performansı kriter puanı üst sınırı aşıldı.');
            $score=array_sum($d['criterion_scores']); $d['total_score']=$score;
            // Madde 32 is higher priority than conflicting EK-18/B tier labels.
            $d['authorization_years']=array_values($d['criterion_scores'])[0]<14?0:($score>=80?3:($score>=60?1:0));
            $d['authorization_until']=now()->addYears($d['authorization_years'])->toDateString();
            foreach($d['evidence_documents'] as $row) { $id=is_array($row)?($row['document_id']??null):$row;if(!$id || !app(Eligibility::class)->cleanDocument($id))$fail('Performans kanıt belgeleri temiz taranmış olmalıdır.');Gate::authorize('record-view',Records::get('documents',$id)); }
        }
        if ($type==='feedback' && $d['source_role']!==$a->role) $fail('Geri bildirim kaynağı aktif görevle uyuşmalıdır.');
        if($type==='annual_reports'){$d['board_document_id']=$old?->board_document_id;foreach($d['outcome_attainment'] as &$row){
            if(!is_array($row)||!isset($row['program_output_id'],$row['students_above_60_percent']) || !is_numeric($row['students_above_60_percent']) || $row['students_above_60_percent']<0 || $row['students_above_60_percent']>100)$fail('Yıllık raporda program çıktısı ve 0–100 başarı oranı gereklidir.');
            $output=Records::get('program_outputs',$row['program_output_id']);Gate::authorize('record-view',$output);if($output->institution_id!==$d['institution_id'] || (!empty($d['program_id'])&&$output->program_id!==$d['program_id']))$fail('Rapor çıktısının program kapsamı uyuşmalıdır.');$row['target_percent']=$output->target_percent;$row['target_met']=$row['students_above_60_percent']>=$output->target_percent;
        }unset($row);}
        if ($type==='risk_plans') {
            $site=Records::get('company_sites',$d['site_id']); $years=match($site->hazard_class){'az_tehlikeli'=>6,'tehlikeli'=>4,default=>2}; if($d['renewal_on']>\Carbon\Carbon::parse($d['assessed_on'])->addYears($years)->toDateString() || $d['renewal_on']<$d['assessed_on']) $fail('Risk planı yenileme süresi tehlike sınıfının sınırları içinde olmalıdır.');
            foreach($d['hazard_checklist'] as $row) {
                if(!is_array($row)||!in_array($row['value']??null,['Uygun','Önlem gerekli','Uygulanabilir değil']))$fail('EK-20 tehlike kontrol durumu geçersiz.');
                if(($row['value']??null)==='Uygulanabilir değil'){if(empty($row['note']))$fail('Uygulanabilir olmayan tehlike için gerekçe gereklidir.');continue;}
                if(!is_array($row)||empty($row['value'])||!isset($row['probability'],$row['severity'])||!is_numeric($row['probability'])||!is_numeric($row['severity'])||$row['probability']<1||$row['probability']>5||$row['severity']<1||$row['severity']>5)$fail('EK-20 tehlike satırında durum, 1–5 olasılık ve şiddet gereklidir.');
            }
            foreach($d['emergency_scenarios'] as $row)foreach(['value','responsible','notify','permanent_solution'] as $key)if(!is_array($row)||empty($row[$key])||!is_string($row[$key]))$fail('EK-20 senaryolarında ilk müdahale, sorumlu, bildirim ve kalıcı çözüm gereklidir.');
            foreach($d['team'] as $row)if(!is_array($row)||empty($row['name'])||empty($row['role']))$fail('Risk ekibinde ad ve görev gereklidir.');
            foreach($d['emergency_contacts'] as $row)if(!is_array($row)||empty($row['name'])||empty($row['role'])||empty($row['phone']))$fail('Acil durum iletişiminde ad, görev ve telefon gereklidir.');
        }
        foreach ($spec=config("domain.$type.fields") as $key=>$def) if ($def['ref']==='documents' && !empty($d[$key]) && !app(Eligibility::class)->cleanDocument($d[$key])) $fail('İlişkili belgeler temiz tarama sonucuna sahip olmalıdır.');
    }
}
