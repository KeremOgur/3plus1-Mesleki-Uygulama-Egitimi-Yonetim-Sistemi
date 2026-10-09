<?php
namespace Tests\Feature;

use App\Domain\{Eligibility,Records};
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixture as F;
use Tests\TestCase;

class ProgramOfferIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private function policy(array $g): void
    {
        F::make('matching_policies',Records::scope($g['app'])+['revision'=>1,'preference_weight'=>.75,'transport_weight'=>.25,'max_preferences'=>5,'transport_rules'=>[['max_minutes'=>10,'score'=>100],['score'=>20]],'seed'=>'program-isolation','time_limit_seconds'=>10,'decision_document_id'=>$g['doc']->id,'state'=>'onaylandi']);
    }

    private function login(array $g): void
    {
        Sanctum::actingAs($g['u']);
        $this->withHeaders(['X-Assignment-Id'=>$g['role']->id,'Idempotency-Key'=>(string)Str::uuid()]);
    }

    private function offerFor(array $g,$program): array
    {
        $scope=['institution_id'=>$g['institution']->id];
        $company=F::make('companies',$scope+['organization_type'=>'ozel','legal_name'=>'Program B işletmesi','tax_no'=>Str::random(10),'state'=>'aktif']);
        $s=$scope+['company_id'=>$company->id];
        $site=F::make('company_sites',$s+['capacity_type'=>'ortak','total_capacity'=>2,'hazard_class'=>'az_tehlikeli']);
        $doc=F::make('documents',$s+['target_type'=>'companies','target_id'=>$company->id,'scan_status'=>'temiz','classification'=>'kurum_ici']);
        $trainer=F::make('trainer_qualifications',$s+['site_id'=>$site->id,'user_id'=>User::factory()->create()->id,'degree'=>'lisans','experience_years'=>3,'qualification_document_id'=>$doc->id,'ohs_document_id'=>$doc->id,'state'=>'onaylandi']);
        $assessment=F::make('company_assessments',$s+['program_id'=>$program->id,'site_id'=>$site->id,'trainer_id'=>$trainer->id,'valid_from'=>'2026-01-01','valid_until'=>'2028-01-01','state'=>'onaylandi']);
        $protocol=F::make('protocols',$s+['signed_document_id'=>$doc->id,'signed_on'=>'2026-01-01','valid_from'=>'2026-01-01','valid_until'=>'2029-01-01','state'=>'aktif']);
        F::make('protocol_scopes',$s+['program_id'=>$program->id,'protocol_id'=>$protocol->id,'site_id'=>$site->id]);
        $offer=F::make('offers',$s+['program_id'=>$program->id,'term_id'=>$g['term']->id,'site_id'=>$site->id,'protocol_id'=>$protocol->id,'assessment_id'=>$assessment->id,'trainer_id'=>$trainer->id,'capacity'=>1,'interview_required'=>false,'state'=>'ilan_edildi']);
        return compact('company','site','trainer','assessment','protocol','offer');
    }

    public function test_same_institution_other_program_is_hidden_in_lists_details_history_and_portal(): void
    {
        $g=F::graph();
        $program=F::make('programs',['institution_id'=>$g['institution']->id,'department_id'=>$g['dept']->id,'code'=>'B','name'=>'Program B']);
        $b=$this->offerFor($g,$program);$this->policy($g);$this->login($g);
        $this->assertSame([],app(Eligibility::class)->offerReasons($b['offer']));
        $this->assertNotSame($g['prog']->id,$program->id);
        foreach(['offers'=>'offer','companies'=>'company','company_sites'=>'site','protocols'=>'protocol','company_assessments'=>'assessment','trainer_qualifications'=>'trainer'] as $type=>$key){
            $own=$g[$key];$foreign=$b[$key];
            $list=$this->getJson('/api/v1/resources/'.$type)->assertOk()->json();
            $this->assertContains($own->id,array_column($list['items'],'id'));
            $this->assertNotContains($foreign->id,array_column($list['items'],'id'));
            $this->getJson('/api/v1/resources/'.$type.'/'.$own->id)->assertOk();
            $this->getJson('/api/v1/resources/'.$type.'/'.$foreign->id)->assertForbidden();
            $this->getJson('/api/v1/resources/'.$type.'/'.$foreign->id.'/history')->assertForbidden();
        }
        $this->getJson('/api/v1/resources/offers?program_id='.$program->id)->assertOk()->assertJsonCount(0,'items')->assertJsonPath('total',0);
        $this->getJson('/api/v1/resources/company_sites?company_id='.$b['company']->id)->assertOk()->assertJsonCount(0,'items');
        $before=Records::query('preferences')->where('application_id',$g['app']->id)->count();$version=$g['app']->version;
        $this->putJson('/api/v1/applications/'.$g['app']->id.'/preferences',['version'=>$version,'preferences'=>[['offer_id'=>$b['offer']->id,'reachable'=>true,'one_way_minutes'=>20]]])->assertForbidden();
        $this->assertSame($before,Records::query('preferences')->where('application_id',$g['app']->id)->count());
        $this->assertSame($version,$g['app']->refresh()->version);
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->putJson('/api/v1/applications/'.$g['app']->id.'/preferences',['version'=>$version,'preferences'=>[['offer_id'=>$g['offer']->id,'reachable'=>true,'one_way_minutes'=>20],['offer_id'=>$b['offer']->id,'reachable'=>true,'one_way_minutes'=>20]]])->assertForbidden();
        $this->assertSame($before,Records::query('preferences')->where('application_id',$g['app']->id)->count(),'Mixed valid/foreign preference list must roll back atomically');
        $this->assertSame($version,$g['app']->refresh()->version);
        $policy=Records::query('matching_policies')->where('program_id',$g['prog']->id)->firstOrFail();
        $snapshot=app(\App\Domain\Matching::class)->snapshot($policy);
        $this->assertNotContains($b['offer']->id,array_column($snapshot['offers'],'id'));
        $this->actingAs($g['u'],'web')->withSession(['assignment_id'=>$g['role']->id,'password_hash_web'=>$g['u']->password]);
        $this->get('/panel/offers/'.$g['offer']->id)->assertOk();
        foreach(['offers'=>'offer','companies'=>'company','company_sites'=>'site','protocols'=>'protocol','trainer_qualifications'=>'trainer'] as $type=>$key){
            $this->get('/panel/'.$type.'/'.$b[$key]->id)->assertForbidden();
            $this->getJson('/portal-api/v1/resources/'.$type.'/'.$b[$key]->id)->assertForbidden();
        }
    }

    public static function unsuitableOffers(): array
    {
        return [
            'unpublished'=>['offer','state','onaylandi'],
            'inactive protocol'=>['protocol','state','imza_bekliyor'],
            'expired protocol'=>['protocol','valid_until','2026-09-30'],
            'unapproved assessment'=>['assessment','state','taslak'],
            'expired assessment'=>['assessment','valid_until','2026-09-30'],
            'missing assessment condition'=>['assessment','ohs_suitable',false],
            'unapproved trainer'=>['trainer','state','taslak'],
            'insufficient trainer experience'=>['trainer','experience_years',2],
            'quarantined required document'=>['doc','scan_status','bekliyor'],
            'zero capacity'=>['offer','capacity',0],
        ];
    }

    #[DataProvider('unsuitableOffers')]
    public function test_unsuitable_offer_is_hidden_and_cannot_be_submitted(string $record,string $field,mixed $value): void
    {
        $g=F::graph();$this->policy($g);$g[$record]->$field=$value;$g[$record]->save();$this->login($g);
        $this->getJson('/api/v1/resources/offers')->assertOk()->assertJsonCount(0,'items')->assertJsonPath('total',0);
        $this->getJson('/api/v1/resources/offers/'.$g['offer']->id)->assertForbidden();
        $this->getJson('/api/v1/resources/companies/'.$g['company']->id)->assertForbidden();
        $count=Records::query('preferences')->where('application_id',$g['app']->id)->count();$version=$g['app']->version;
        $this->putJson('/api/v1/applications/'.$g['app']->id.'/preferences',['version'=>$version,'preferences'=>[['offer_id'=>$g['offer']->id,'reachable'=>true,'one_way_minutes'=>20]]])->assertForbidden();
        $this->assertSame($count,Records::query('preferences')->where('application_id',$g['app']->id)->count());
        $this->assertSame($version,$g['app']->refresh()->version);
    }

    public function test_valid_offer_supports_ordered_revision_submission_and_transport_verification(): void
    {
        $g=F::graph();$other=$this->offerFor($g,$g['prog']);$this->policy($g);$this->login($g);
        $payload=['version'=>$g['app']->version,'preferences'=>array_map(fn($id)=>['offer_id'=>$id,'reachable'=>true,'one_way_minutes'=>20],[$other['offer']->id,$g['offer']->id])];
        $result=$this->putJson('/api/v1/applications/'.$g['app']->id.'/preferences',$payload)->assertOk()->assertJsonPath('revision',2)->assertJsonPath('preferences.0.rank',1)->assertJsonPath('preferences.1.rank',2)->assertJsonPath('preferences.0.offer_id',$other['offer']->id)->json();
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/api/v1/applications/'.$g['app']->id.'/submit-preferences',['version'=>$result['application_version'],'revision'=>2])->assertOk();
        $this->assertSame(2,Records::query('preferences')->where('application_id',$g['app']->id)->where('revision',2)->whereNotNull('submitted_at')->count());
        $a=F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'user_id'=>$g['u']->id,'role'=>'bolum_komisyon','valid_from'=>now()->subDay(),'state'=>'aktif']);
        $this->withHeaders(['X-Assignment-Id'=>$a->id,'Idempotency-Key'=>(string)Str::uuid()])->postJson('/api/v1/applications/'.$g['app']->id.'/verify-transport',['version'=>$g['app']->refresh()->version,'revision'=>2,'reason'=>'Sentetik ulaşım kontrolü'])->assertOk();
        $this->assertTrue(app(Eligibility::class)->pair($g['app']->refresh(),$other['offer'])['eligible']);
        $policy=Records::query('matching_policies')->where('program_id',$g['prog']->id)->firstOrFail();
        $snapshot=app(\App\Domain\Matching::class)->snapshot($policy);$pairs=array_column($snapshot['candidates'],null,'offer_id');
        $this->assertEquals(80,$pairs[$other['offer']->id]['score']);
        $this->assertEquals(65,$pairs[$g['offer']->id]['score']);
        $this->assertEquals(80,$pairs[$g['offer']->id]['preference_score']);
        $this->assertEquals(20,$pairs[$g['offer']->id]['transport_score']);
    }

    public function test_program_commission_cannot_verify_another_program_application(): void
    {
        $g=F::graph();$b=F::make('programs',['institution_id'=>$g['institution']->id,'department_id'=>$g['dept']->id,'code'=>'B']);
        $a=F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$b->id,'user_id'=>$g['u']->id,'role'=>'bolum_komisyon','valid_from'=>now()->subDay(),'state'=>'aktif']);
        $g['pref']->transport_verified=false;$g['pref']->save();$this->login($g);$this->withHeader('X-Assignment-Id',$a->id);
        $this->getJson('/api/v1/resources/applications/'.$g['app']->id)->assertForbidden();
        $this->postJson('/api/v1/applications/'.$g['app']->id.'/verify-transport',['version'=>$g['app']->version,'revision'=>1,'reason'=>'Kapsam dışı kontrol'])->assertForbidden();
        $this->assertFalse($g['pref']->refresh()->transport_verified);
        $this->assertSame(0,Records::query('decisions')->where('program_id',$g['prog']->id)->count());
    }
}
