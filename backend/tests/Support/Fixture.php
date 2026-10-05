<?php
namespace Tests\Support;
use App\Domain\Records;
use App\Models\User;
use Illuminate\Support\Str;
class Fixture
{
    public static function make(string $type,array $data=[])
    {
        foreach(config("domain.$type.fields") as $key=>$def) if(!array_key_exists($key,$data) && (str_starts_with($def['rules'],'required') || str_starts_with($def['rules'],'present')) && !$def['ref']) {
            $data[$key]=match($def['type']) {
                'string','text'=>(string)Str::uuid(), 'integer','decimal','bigint'=>1,
                'boolean'=>true,'date'=>today()->toDateString(),'timestamp'=>now(),'jsonb'=>[],
                'uuid'=>(string)Str::uuid(),
            };
        }
        return Records::create($type,$data);
    }
    public static function graph(): array
    {
        $u=User::factory()->create(['active'=>true]);
        $institution=self::make('institutions',['code'=>'TEST-'.Str::random(6),'name'=>'Sentetik MYO']);
        $scope=['institution_id'=>$institution->id];
        $dept=self::make('departments',$scope+['code'=>'TEST','name'=>'Bölüm']);
        $prog=self::make('programs',$scope+['department_id'=>$dept->id,'code'=>'BP','name'=>'Bilgisayar Programcılığı','active_student_count'=>15,'ects'=>30,'workplace_hours'=>600,'independent_hours'=>300,'weeks'=>15]);
        $term=self::make('academic_terms',$scope+['code'=>'2026-GUZ','starts_on'=>'2026-10-01','ends_on'=>'2027-01-14','application_deadline'=>now()->addWeek(),'preference_opens_at'=>now()->subWeek(),'preference_deadline'=>now()->addWeek(),'academic_year_end'=>'2027-07-01','next_year_starts_on'=>'2027-09-01','pass_threshold'=>60,'letter_grade_rules'=>[['minimum'=>60,'letter'=>'CC'],['minimum'=>0,'letter'=>'FF']],'holiday_dates'=>[],'state'=>'acik']);
        $company=self::make('companies',$scope+['organization_type'=>'ozel','legal_name'=>'Sentetik İşletme','tax_no'=>Str::random(10),'state'=>'aktif']);
        $site=self::make('company_sites',$scope+['company_id'=>$company->id,'name'=>'Şube','capacity_type'=>'ortak','total_capacity'=>2,'hazard_class'=>'az_tehlikeli']);
        $doc=self::make('documents',$scope+['company_id'=>$company->id,'target_type'=>'companies','target_id'=>$company->id,'object_key'=>'test.txt','mime'=>'application/pdf','scan_status'=>'temiz','classification'=>'kurum_ici','retention_until'=>now()->addYears(5)->toDateString(),'legal_hold'=>false]);
        $trainer=self::make('trainer_qualifications',$scope+['company_id'=>$company->id,'site_id'=>$site->id,'user_id'=>$u->id,'degree'=>'lisans','experience_years'=>3,'qualification_document_id'=>$doc->id,'ohs_document_id'=>$doc->id,'state'=>'onaylandi']);
        $assessment=self::make('company_assessments',$scope+['company_id'=>$company->id,'program_id'=>$prog->id,'site_id'=>$site->id,'trainer_id'=>$trainer->id,'valid_from'=>'2026-01-01','valid_until'=>'2028-01-01','state'=>'onaylandi']);
        $protocol=self::make('protocols',$scope+['company_id'=>$company->id,'signed_document_id'=>$doc->id,'signed_on'=>'2026-01-01','valid_from'=>'2026-01-01','valid_until'=>'2029-01-01','state'=>'aktif']);
        self::make('protocol_scopes',$scope+['company_id'=>$company->id,'program_id'=>$prog->id,'protocol_id'=>$protocol->id,'site_id'=>$site->id]);
        $offer=self::make('offers',$scope+['company_id'=>$company->id,'program_id'=>$prog->id,'term_id'=>$term->id,'site_id'=>$site->id,'protocol_id'=>$protocol->id,'assessment_id'=>$assessment->id,'trainer_id'=>$trainer->id,'capacity'=>1,'interview_required'=>false,'state'=>'ilan_edildi']);
        $student=self::make('students',$scope+['program_id'=>$prog->id,'user_id'=>$u->id,'student_no'=>Str::random(10),'gpa'=>3.2,'gpa_scale'=>4]);
        $app=self::make('applications',$scope+['student_id'=>$student->id,'program_id'=>$prog->id,'term_id'=>$term->id,'gpa_snapshot'=>3.2,'eligibility'=>'uygun','state'=>'uygun']);
        $pref=self::make('preferences',$scope+['student_id'=>$student->id,'program_id'=>$prog->id,'term_id'=>$term->id,'company_id'=>$company->id,'application_id'=>$app->id,'offer_id'=>$offer->id,'rank'=>1,'revision'=>1,'one_way_minutes'=>20,'reachable'=>true,'transport_verified'=>true,'submitted_at'=>now()]);
        $role=self::make('role_assignments',$scope+['student_id'=>$student->id,'user_id'=>$u->id,'role'=>'ogrenci','valid_from'=>now()->subDay(),'state'=>'aktif']);
        return compact('u','institution','dept','prog','term','company','site','doc','trainer','assessment','protocol','offer','student','app','pref','role');
    }
}
