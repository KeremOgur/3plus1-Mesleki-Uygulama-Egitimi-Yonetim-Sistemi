<?php
namespace App\Domain;
class Maintenance
{
    private function alert($r,string $key,string $message): void
    {
        $event='hatirlatma:'.$key.':'.today()->toDateString();
        if(!Records::query('outbox_events')->where('aggregate_id',$r->id)->where('event_type',$event)->exists()) app(Outbox::class)->record($r,$event,$message);
    }
    public function run(): array
    {
        $counts=['placements'=>0,'protocols'=>0];
        foreach(Records::query('placements')->whereIn('state',array_merge(Eligibility::ACTIVE,['tamamlandi']))->get() as $p) {
            $counts['placements']++;
            $summary=app(Education::class)->attendanceSummary($p);$term=Records::get('academic_terms',$p->term_id);
            if($summary['failed']) $this->alert($p,'devamsizlik','Devamsızlık %20 sınırını aştı; değerlendirme engellendi.');
            elseif($term->absence_warning_percent!==null && $summary['absence_percent']>=$term->absence_warning_percent) $this->alert($p,'devamsizlik_yaklasti','Devamsızlık sınırına yaklaşılıyor.');
            if(in_array($p->state,['basladi','tamamlandi'])) {
                $monitoring=app(Education::class)->monitoring($p);
                $elapsed=max(0,min(15,(int)floor(\Carbon\Carbon::parse($p->starts_on)->diffInDays(today(),false)/7)));
                if(array_filter($monitoring['missing_weeks'],fn($w)=>$w<=$elapsed)) $this->alert($p,'denetim','Haftalık akademik danışman denetim kaydı eksik.');
                $weeks=Records::query('weekly_reports')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->orderByDesc('revision')->get()->unique('week_no')->where('state','danisman_onayi')->pluck('week_no')->all();
                if(array_diff(range(1,max(1,$elapsed)),$weeks) && $elapsed>0)$this->alert($p,'haftalik_rapor','Tamamlanan haftalar için eğitici ve danışman onaylı faaliyet raporu eksik.');
                $planDeadline=app(BusinessCalendar::class)->deadline($p->starts_on,10,$term);
                if(now()->greaterThan($planDeadline) && !Records::query('learning_plans')->where('placement_id',$p->id)->whereNotNull('prepared_on')->exists()) $this->alert($p,'plan','Öğrenme planının on iş günü hazırlama süresi geçti.');
                if(today()->greaterThan($p->ends_on) && !$monitoring['workplace_requirement_met']) $this->alert($p,'is_yeri_denetimi','Dönemde en az iki işyeri denetimi eksik.');
                $fileDeadline=app(BusinessCalendar::class)->deadline($p->ends_on,10,$term);
                if(now()->greaterThan($fileDeadline) && !Records::query('training_file_deliveries')->where('placement_id',$p->id)->exists()) $this->alert($p,'egitim_dosyasi','Basılı ve elektronik eğitim dosyası teslimi eksik.');
            }
        }
        foreach(Records::query('protocols')->where('state','aktif')->get() as $p) { $counts['protocols']++; if(\Carbon\Carbon::parse($p->valid_until)->diffInDays(today(),false)>=-30) $this->alert($p,'protokol','Protokol bitişi ve otuz günlük fesih bildirim süresi kontrol edilmelidir.'); }
        foreach(Records::query('incidents')->get() as $r) {
            if(!$r->directorate_notified_at && \Carbon\Carbon::parse($r->occurred_at)->lessThan(now()->startOfDay())) $this->alert($r,'ayni_gun_bildirim','İş kazası/olay için aynı gün Müdürlük bildirimi eksik.');
            $t=Records::get('academic_terms',$r->term_id);if(!$r->investigation_document_id && now()->greaterThan(app(BusinessCalendar::class)->deadline($r->occurred_at,3,$t))) $this->alert($r,'inceleme','Olayın üç iş günü inceleme raporu eksik.');
        }
        foreach(Records::query('change_requests')->whereNotNull('interruption_on')->where('student_fault',false)->whereNotIn('state',['uygulandi','reddedildi'])->get() as $r) { $t=Records::get('academic_terms',$r->term_id);if(now()->greaterThan(app(BusinessCalendar::class)->deadline($r->interruption_on,10,$t)))$this->alert($r,'nakil','Kusursuz kesintide on iş günü yeniden yerleştirme süresi geçti.'); }
        foreach(Records::query('payroll')->get() as $r) { $due=\Carbon\Carbon::parse($r->month.'-01')->addMonth()->day(5)->endOfDay();if(\Carbon\Carbon::parse($r->reported_on)->greaterThan($due))$this->alert($r,'puantaj','Puantaj takip eden ayın beşinci günü bildirim süresini aştı.'); }
        foreach(Records::query('fund_contributions')->get() as $r) if(\Carbon\Carbon::parse($r->reported_on)->day>25)$this->alert($r,'katki','Devlet katkısı belgeleri ayın 25. günü bildirim süresini aştı.');
        foreach(Records::query('risk_plans')->where('renewal_on','<',today())->get() as $r)$this->alert($r,'risk_plani','Risk planının yenileme süresi geçti.');
        foreach(Records::query('company_performance')->where('state','onaylandi')->get() as $r) {
            if(($r->criterion_scores[0]??20)>=14)continue;
            foreach(Records::query('placements')->where('company_id',$r->company_id)->whereIn('state',Eligibility::ACTIVE)->get() as $p) {
                $deadline=app(BusinessCalendar::class)->deadline($r->updated_at,5,Records::get('academic_terms',$p->term_id));
                $this->alert($p,'isg_nakil',now()->greaterThan($deadline)?'İSG performansı nedeniyle beş iş günü nakil süresi aşıldı.':'İSG performansı nedeniyle öğrenci en geç beş iş günü içinde nakledilmelidir.');
            }
        }
        foreach(Records::query('academic_terms')->get() as $term) {
            foreach(['application_deadline'=>'Başvuru süresi bugün sona eriyor.','preference_deadline'=>'Tercih kesinleştirme süresi bugün sona eriyor.'] as $field=>$message)if(\Carbon\Carbon::parse($term->$field)->isToday())foreach(Records::query('applications')->where('term_id',$term->id)->get() as $application)$this->alert($application,$field,$message);
            if(today()->greaterThan(\Carbon\Carbon::parse($term->academic_year_end)->addMonths(2)) && !Records::query('annual_reports')->where('term_id',$term->id)->whereNotIn('state',['taslak','iade'])->exists())$this->alert($term,'yillik_rapor','Yıl bitimini izleyen iki ay içinde yıllık değerlendirme raporu hazırlanmadı.');
            if(today()->greaterThan(\Carbon\Carbon::parse($term->next_year_starts_on)->addMonth()) && !Records::query('annual_reports')->where('term_id',$term->id)->whereNotNull('board_document_id')->exists())$this->alert($term,'yillik_kurul','Yıllık raporun izleyen akademik yılın ilk ayı içinde kurul sunumu eksik.');
        }
        foreach(Records::query('improvement_actions')->where('state','!=','kapandi')->where('due_on','<',today())->get() as $r)$this->alert($r,'iyilestirme','İyileştirme eyleminin termin tarihi geçti.');
        foreach(Records::query('students')->whereNotNull('graduated_on')->get() as $student) {
            $minimum=\Carbon\Carbon::parse($student->graduated_on)->addYears(5);
            if(!$student->retained_until||\Carbon\Carbon::parse($student->retained_until)->lessThan($minimum)){$student->retained_until=$minimum;$student->save();}
            foreach(config('domain') as $type=>$spec) {
                if($type==='institutions')continue;
                foreach(Records::query($type)->where('student_id',$student->id)->where(fn($q)=>$q->whereNull('retained_until')->orWhere('retained_until','<',$minimum))->get() as $r){$r->retained_until=$minimum;if($type==='documents'&&(!$r->retention_until||\Carbon\Carbon::parse($r->retention_until)->lessThan($minimum)))$r->retention_until=$minimum->toDateString();$r->save();}
            }
        }
        return $counts;
    }
}
