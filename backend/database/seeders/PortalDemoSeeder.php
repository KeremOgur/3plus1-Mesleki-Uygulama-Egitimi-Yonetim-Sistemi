<?php
namespace Database\Seeders;
use App\Domain\Records;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;

class PortalDemoSeeder extends Seeder
{
    private function make(string $type,array $data=[])
    {
        foreach(config("domain.$type.fields") as $key=>$def)if(!array_key_exists($key,$data)&&str_starts_with($def['rules'],'required')&&!$def['ref'])$data[$key]=match($def['type']){'string','text'=>'DEMO — örnek kayıt','integer','decimal','bigint'=>1,'boolean'=>true,'date'=>today()->toDateString(),'timestamp'=>now(),'jsonb'=>[],'uuid'=>(string)Str::uuid()};
        return Records::create($type,$data);
    }
    public function run(): void
    {
        if(!app()->environment('local','testing'))throw new \RuntimeException('Demo verisi yalnız local/testing ortamında oluşturulabilir.');
        if(Records::query('institutions')->where('code','DEMO-MUE')->exists()){ $this->command?->info('Demo mevcut; özel depodaki hesap dosyasını kullanın.');return; }
        DB::transaction(function(){
            Records::auditContext(null,null,'Sentetik portal gösterimi; resmî akademik karar değildir.');
            $password='MueDemo!'.Str::random(20);$users=[];$accounts=[];
            foreach(['ogrenci'=>'Deniz Öğrenci','akademik_danisman'=>'Dr. Ayşe Danışman','isletme_yetkilisi'=>'Mehmet İşletme Yetkilisi','egitici'=>'Selin Eğitici','mudur'=>'Prof. Ali MYO Müdürü','mue_komisyon'=>'MUE Komisyon Temsilcisi','bolum_komisyon'=>'Bölüm Komisyon Temsilcisi','sistem_yoneticisi'=>'Teknik Yönetici'] as $role=>$name){$u=User::create(['name'=>$name,'email'=>$role.'@demo.mue.invalid','password'=>$password,'active'=>true]);$u->forceFill(['external_subject'=>'mue-demo:'.$role])->save();$users[$role]=$u;$accounts[]=['role'=>$role,'email'=>$u->email,'password'=>$password];}
            $inst=$this->make('institutions',['code'=>'DEMO-MUE','name'=>'DEMO — Elazığ OSB MYO']);$scope=['institution_id'=>$inst->id];
            $dept=$this->make('departments',$scope+['code'=>'DEMO-B','name'=>'Bilgisayar Teknolojileri']);
            $prog=$this->make('programs',$scope+['department_id'=>$dept->id,'code'=>'DEMO-BP','name'=>'Bilgisayar Programcılığı','active_student_count'=>20,'ects'=>30,'workplace_hours'=>600,'independent_hours'=>300,'weeks'=>15]);
            $start=today()->startOfWeek();$end=$start->copy()->addWeeks(14)->addDays(4);
            $term=$this->make('academic_terms',$scope+['code'=>'DEMO-'.today()->year.'-GUZ','starts_on'=>$start->toDateString(),'ends_on'=>$end->toDateString(),'application_deadline'=>now()->addWeek(),'preference_opens_at'=>now()->subWeek(),'preference_deadline'=>now()->addWeeks(2),'placement_appeal_deadline'=>now()->addWeeks(3),'academic_year_end'=>$end->copy()->addMonths(5)->toDateString(),'next_year_starts_on'=>$end->copy()->addMonths(8)->toDateString(),'pass_threshold'=>60,'letter_grade_rules'=>[['minimum'=>90,'letter'=>'AA'],['minimum'=>60,'letter'=>'CC'],['minimum'=>0,'letter'=>'FF']],'holiday_dates'=>[],'state'=>'acik']);
            $company=$this->make('companies',$scope+['legal_name'=>'DEMO — Fırat Yazılım Atölyesi','organization_type'=>'ozel','sector'=>'Yazılım ve otomasyon','tax_no'=>'DEMO000001','personnel_count'=>24,'contact_name'=>'Mehmet İşletme Yetkilisi','email'=>'iletisim@demo.mue.invalid','phone'=>'000 000 00 00','state'=>'aktif']);
            $site=$this->make('company_sites',$scope+['company_id'=>$company->id,'name'=>'DEMO — Teknoloji Atölyesi','province'=>'Elazığ','district'=>'Merkez','address'=>'Sentetik kampüs adresi; gerçek işyeri değildir.','shuttle'=>true,'meal'=>true,'capacity_type'=>'ortak','total_capacity'=>5,'hazard_class'=>'az_tehlikeli']);
            $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jG0kAAAAASUVORK5CYII=');$key='karantina/demo-'.Str::uuid().'.png';Storage::disk('local')->put($key,$bytes);
            $doc=$this->make('documents',$scope+['company_id'=>$company->id,'target_type'=>'companies','target_id'=>$company->id,'object_key'=>$key,'original_name'=>'DEMO-tarama-bekleyen-belge.png','mime'=>'image/png','size_bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'scan_status'=>'bekliyor','classification'=>'kurum_ici','retention_start_event'=>'demo','retention_until'=>now()->addYears(5)->toDateString(),'legal_hold'=>false,'state'=>'karantina']);
            $trainer=$this->make('trainer_qualifications',$scope+['company_id'=>$company->id,'site_id'=>$site->id,'user_id'=>$users['egitici']->id,'degree'=>'lisans','experience_years'=>5,'qualification_document_id'=>$doc->id,'ohs_document_id'=>$doc->id,'state'=>'taslak']);
            $protocol=$this->make('protocols',$scope+['company_id'=>$company->id,'signed_document_id'=>$doc->id,'signed_on'=>$start->toDateString(),'valid_from'=>$start->toDateString(),'valid_until'=>$start->copy()->addYears(3)->toDateString(),'state'=>'taslak']);
            $assessment=$this->make('company_assessments',$scope+['company_id'=>$company->id,'site_id'=>$site->id,'program_id'=>$prog->id,'trainer_id'=>$trainer->id,'valid_from'=>$start->toDateString(),'valid_until'=>$end->copy()->addYear()->toDateString(),'state'=>'taslak']);
            $offer=$this->make('offers',$scope+['company_id'=>$company->id,'program_id'=>$prog->id,'term_id'=>$term->id,'site_id'=>$site->id,'trainer_id'=>$trainer->id,'protocol_id'=>$protocol->id,'assessment_id'=>$assessment->id,'capacity'=>5,'interview_required'=>false,'state'=>'taslak']);
            $student=$this->make('students',$scope+['program_id'=>$prog->id,'user_id'=>$users['ogrenci']->id,'student_no'=>'DEMO-2026001','gpa'=>3.2,'gpa_scale'=>4,'graduation_exception'=>false,'academic_source'=>'DEMO — sentetik kayıt','academic_fetched_at'=>now(),'academic_data'=>['Veri kaynağı'=>'Gösterim; OBS sorgusu yapılmadı.'],'state'=>'taslak']);
            $app=$this->make('applications',$scope+['program_id'=>$prog->id,'term_id'=>$term->id,'student_id'=>$student->id,'gpa_snapshot'=>3.2,'gpa_source'=>'DEMO','gpa_recorded_at'=>now(),'eligibility'=>'uygun','eligibility_reason'=>'Sentetik eğitim senaryosu','state'=>'uygun']);
            // A pre-existing synthetic placement is used solely to demonstrate report/review screens.
            // No scan, signed decision, institutional publication or production approval is simulated.
            $placement=$this->make('placements',$scope+['program_id'=>$prog->id,'term_id'=>$term->id,'student_id'=>$student->id,'company_id'=>$company->id,'application_id'=>$app->id,'offer_id'=>$offer->id,'starts_on'=>$start->toDateString(),'ends_on'=>$end->toDateString(),'credited_minutes'=>0,'credited_outcomes'=>[],'reason'=>'DEMO — önceden hazırlanmış eğitim senaryosu; resmî karar yok.','state'=>'ilan_edildi']);
            $ps=Records::scope($placement);
            foreach($users as $role=>$u){$data=$scope+['user_id'=>$u->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif'];if($role==='ogrenci')$data['student_id']=$student->id;if($role==='bolum_komisyon')$data['program_id']=$prog->id;if(in_array($role,['isletme_yetkilisi','egitici']))$data['company_id']=$company->id;$this->make('role_assignments',$data);}
            $this->make('adviser_assignments',$ps+['user_id'=>$users['akademik_danisman']->id,'valid_from'=>$start->toDateString(),'valid_until'=>$end->toDateString(),'state'=>'aktif']);
            $this->make('trainer_assignments',$ps+['trainer_id'=>$trainer->id,'valid_from'=>$start->toDateString(),'valid_until'=>$end->toDateString(),'state'=>'aktif']);
            $output=$this->make('program_outputs',$scope+['program_id'=>$prog->id,'board_document_id'=>$doc->id,'code'=>'PÇ-1','description'=>'Mesleki problemi analiz edip çözüm tasarlayabilme.']);
            $outcome=$this->make('learning_outcomes',$scope+['program_id'=>$prog->id,'program_output_id'=>$output->id,'code'=>'ÖK-1','description'=>'Güvenli yazılım geliştirme ve test uygulama.','tyyc_component'=>'beceri']);
            $report=$this->make('weekly_reports',$ps+['week_no'=>1,'week_start'=>$start->toDateString(),'week_end'=>$start->copy()->addDays(4)->toDateString(),'unit'=>'Yazılım geliştirme','activity_text'=>'DEMO — İşletme tanıtımı, araç kurulumu ve örnek görev.','knowledge_skills'=>'DEMO — Sürüm kontrolü ve test iş akışı.','problems_solutions'=>'DEMO — Kurulum sorunu rehberlik ile çözüldü.','revision'=>1,'state'=>'taslak']);
            $this->make('report_outcomes',$ps+['report_id'=>$report->id,'outcome_id'=>$outcome->id,'state'=>'kayitli']);
            $this->make('matching_policies',$scope+['program_id'=>$prog->id,'term_id'=>$term->id,'revision'=>1,'preference_weight'=>.75,'transport_weight'=>.25,'decision_document_id'=>$doc->id,'max_preferences'=>5,'transport_rules'=>[['max_minutes'=>30,'score'=>100],['max_minutes'=>60,'score'=>70],['max_minutes'=>120,'score'=>40]],'seed'=>'demo-2026','time_limit_seconds'=>120,'state'=>'taslak']);
            foreach($users as $role=>$u)$this->make('notifications',$scope+['recipient_id'=>$u->id,'channel'=>'portal','message'=>'DEMO ortamına hoş geldiniz. Örnek veriler resmî kayıt değildir.','sent_at'=>now(),'state'=>'gonderildi']);
            Storage::disk('local')->put('demo-credentials.json',json_encode(['notice'=>'Yalnız geliştirme ortamı. Üretimde bu hesaplarla giriş engellenir.','accounts'=>$accounts],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        });
        $this->command?->info('Demo oluşturuldu. Hesaplar: storage/app/private/demo-credentials.json');
    }
}
