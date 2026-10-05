<?php
namespace App\Domain;
use App\Models\DomainRecord;
class Education
{
    public function attendanceSummary(DomainRecord $p): array
    {
        $placements=Records::query('placements')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->pluck('id');
        $rows=Records::query('attendance')->whereIn('placement_id',$placements)->where('state','onaylandi')->get();
        $planned=$rows->sum('planned_minutes'); $attended=$rows->sum('attended_minutes');
        $absence=$planned-$attended; $total=600*60; $percent=100*$absence/$total;
        return ['planned_minutes'=>$planned,'attended_minutes'=>$attended,'absent_minutes'=>$absence,'expected_minutes'=>$total,'absence_percent'=>round($percent,4),'complete'=>$planned===$total,'failed'=>$absence>$total*.2,'missing_records'=>$planned<$total];
    }
    public function monitoring(DomainRecord $p): array
    {
        $placements=Records::query('placements')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->get();
        $rows=Records::query('inspections')->whereIn('placement_id',$placements->pluck('id'))->where('state','onaylandi')->get();
        $missing=[]; $start=\Carbon\CarbonImmutable::parse($placements->min('starts_on'));
        for($w=1;$w<=15;$w++) { $from=$start->addWeeks($w-1); $until=$from->addWeek(); if(!$rows->contains(fn($r)=>\Carbon\Carbon::parse($r->inspected_at)->between($from,$until->subSecond()))) $missing[]=$w; }
        return ['missing_weeks'=>$missing,'workplace_inspections'=>$rows->where('workplace_inspection',true)->count(),'required_workplace_inspections'=>2,'weekly_requirement_met'=>!$missing,'workplace_requirement_met'=>$rows->where('workplace_inspection',true)->count()>=2];
    }
    public function calculate(DomainRecord $p): DomainRecord
    {
        $term=Records::get('academic_terms',$p->term_id); $attendance=$this->attendanceSummary($p); $reasons=[];
        if (!$attendance['complete']) throw new DomainError('EKSIK_DEVAM','Başarı hesabından önce devam kayıtları tamamlanmalıdır.');
        if ($attendance['failed']) $reasons[]='Mazeretler dâhil devamsızlık %20 sınırını aştı; değerlendirmeye alınamaz.';
        $disciplined=Records::query('nonconformities')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->where('authorized_failure',true)->where('state','kapandi')->exists();
        if ($disciplined) $reasons[]='Yetkili disiplin kararı gereği başarısız.';
        $scores=Records::query('rubric_evaluations')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->where('state','onaylandi')->orderBy('revision','desc')->get()->unique(fn($r)=>$r->source.':'.$r->outcome_id.':'.$r->area);
        $components=[];
        $areas=[]; $weights=['is_yeri'=>.4,'danisman'=>.3,'dosya_portfolyo'=>.2,'sunum'=>.1];
        if (!$reasons) {
            if(!Records::query('presentations')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->where('state','onaylandi')->exists())throw new DomainError('EK11_EKSIK','Başarı hesabı için onaylı dönem sonu sunum kaydı gerekir.');
            if ($term->pass_threshold===null || !$term->letter_grade_rules) throw new DomainError('KURUM_KURALI_EKSIK','Kurumun geçme ve harf notu eşikleri tanımlanmalıdır.');
            foreach ($weights as $source=>$weight) { $data=$scores->where('source',$source); if ($data->isEmpty()) throw new DomainError('EKSIK_BILESEN','Değerlendirme bileşeni eksik: '.$source); $components[$source]=0; }
            foreach (['mesleki'=>30,'problem'=>20,'takim'=>15,'etik'=>15,'isg'=>10,'belgeleme'=>10] as $area=>$max) {
                $value=0;
                foreach($weights as $source=>$weight) { $data=$scores->where('source',$source)->where('area',$area); if($data->isEmpty()) throw new DomainError('EKSIK_KAZANIM','Rubrik kazanım alanı eksik: '.$area); $components[$source]+=$data->avg('score')*$max/100; $value+=$weight*$data->avg('score'); }
                $areas[$area]=round($value/100*$max,4);
            }
            $delivery=Records::query('training_file_deliveries')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->where('state','onaylandi')->latest()->first();
            $deadline=app(BusinessCalendar::class)->deadline($p->ends_on,10,$term)->toDateString();
            if (!$delivery || $delivery->paper_delivered_on>$deadline || $delivery->electronic_delivered_on>$deadline) {
                $components['dosya_portfolyo']=0; $reasons[]='Dosya bileşeni on iş günü içinde basılı ve elektronik teslim edilmedi.';
                foreach(['mesleki'=>30,'problem'=>20,'takim'=>15,'etik'=>15,'isg'=>10,'belgeleme'=>10] as $area=>$max) $areas[$area]=round($areas[$area]-.2*$scores->where('source','dosya_portfolyo')->where('area',$area)->avg('score')*$max/100,4);
            }
            if ($areas['isg']<6) $reasons[]='İSG alanında %60 başarı eşiği sağlanmadı.';
        }
        $total=array_sum(array_map(fn($s,$w)=>($components[$s]??0)*$w,array_keys($weights),$weights));
        $grade=null; if(!$attendance['failed'] && !$disciplined) foreach($term->letter_grade_rules??[] as $r) if($total>=$r['minimum']) { $grade=$r['letter']; break; }
        $outcome=$attendance['failed']||$disciplined||(($areas['isg']??0)<6)||$total<($term->pass_threshold??100)?'basarisiz':'basarili';
        $rev=(Records::query('success_results')->where('student_id',$p->student_id)->where('term_id',$p->term_id)->max('revision')??0)+1;
        return Records::create('success_results',array_merge(Records::scope($p),['placement_id'=>$p->id,'revision'=>$rev,'component_scores'=>$components,'area_scores'=>$areas,'absence_percent'=>$attendance['absence_percent'],'total_score'=>round($total,4),'outcome'=>$outcome,'letter_grade'=>$grade,'reasons'=>$reasons,'evaluation_available'=>!$attendance['failed']&&!$disciplined,'calculation_version'=>'mue-grade-1.0','state'=>'hesaplandi']));
    }
}
