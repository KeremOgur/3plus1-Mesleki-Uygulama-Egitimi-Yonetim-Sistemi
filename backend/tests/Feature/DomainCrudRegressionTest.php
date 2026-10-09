<?php
namespace Tests\Feature;

use App\Domain\{Records,Workflow};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Fixture as F;
use Tests\TestCase;

class DomainCrudRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private function actor(array $g,string $role): void
    {
        $a=F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$role==='bolum_komisyon'?$g['prog']->id:null,'company_id'=>$role==='isletme_yetkilisi'?$g['company']->id:null,'user_id'=>$g['u']->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif']);
        Sanctum::actingAs($g['u']);$this->withHeaders(['X-Assignment-Id'=>$a->id,'Idempotency-Key'=>(string)Str::uuid()]);
    }

    private function send(string $path,array $input,string $method='POST')
    {
        $this->withHeader('Idempotency-Key',(string)Str::uuid());
        return $method==='PUT'?$this->putJson('/api/v1/'.$path,$input):$this->postJson('/api/v1/'.$path,$input);
    }

    public function test_management_catalog_create_read_update_revision_and_stale_version(): void
    {
        $g=F::graph();$this->actor($g,'sistem_yoneticisi');
        $this->send('resources/institutions',['code'=>'QA-'.Str::random(8),'name'=>'Geçici test kurumu'])->assertCreated();
        $this->actor($g,'mudur');
        $dept=$this->send('resources/departments',['code'=>'QA-'.Str::random(8),'name'=>'Test bölümü'])->assertCreated()->json();
        $program=$this->send('resources/programs',['department_id'=>$dept['id'],'code'=>'QA-'.Str::random(8),'name'=>'Test programı','active_student_count'=>15,'ects'=>30,'workplace_hours'=>600,'independent_hours'=>300,'weeks'=>15])->assertCreated()->json();
        $this->getJson('/api/v1/resources/programs/'.$program['id'])->assertOk()->assertJsonPath('department_id',$dept['id']);
        $updated=$this->send('resources/programs/'.$program['id'],['version'=>$program['version'],'reason'=>'Sentetik katalog düzenlemesi','name'=>'Yeni test adı'],'PUT')->assertOk()->json();
        $this->assertGreaterThan($program['version'],$updated['version']);
        $this->getJson('/api/v1/resources/programs/'.$program['id'].'/history')->assertOk()->assertJsonCount(2)->assertJsonPath('1.record.name','Yeni test adı');
        $this->send('resources/programs/'.$program['id'],['version'=>$program['version'],'reason'=>'Eski sürüm','name'=>'Kaydedilmemeli'],'PUT')->assertStatus(409)->assertJsonPath('code','STALE_VERSION');
        $term=$this->send('resources/academic_terms',array_replace($g['term']->only(array_keys(config('domain.academic_terms.fields'))),['code'=>'QA-'.Str::random(8)]))->assertCreated()->json();
        $this->send('resources/academic_terms/'.$term['id'].'/transition',['version'=>$term['version'],'state'=>'acik'])->assertOk()->assertJsonPath('state','acik');
        $this->send('resources/term_programs',['program_id'=>$program['id'],'term_id'=>$term['id'],'active_student_count'=>15,'semester'=>3,'grouping_enabled'=>true,'board_document_id'=>$g['doc']->id,'cohort_rule'=>'Sentetik karar','preference_opens_at'=>now()->subDay()->toISOString(),'preference_deadline'=>now()->addDay()->toISOString()])->assertCreated();
    }

    public function test_foreign_key_duplicate_integer_decimal_enum_and_nullable_inputs_are_controlled(): void
    {
        $g=F::graph();$this->actor($g,'mudur');
        $base=$g['prog']->only(array_keys(config('domain.programs.fields')));$base['code']='QA-'.Str::random(8);
        foreach([['department_id'=>'not-uuid'],['department_id'=>(string)Str::uuid()],['active_student_count'=>1.5],['active_student_count'=>-1],['ects'=>'not-number'],['ects'=>29],['weeks'=>14],['name'=>str_repeat('x',251)]] as $change){
            $r=$this->send('resources/programs',array_replace($base,$change));$this->assertContains($r->status(),[404,409,422],$r->getContent());
        }
        $this->send('resources/programs',$g['prog']->only(array_keys(config('domain.programs.fields'))))->assertStatus(409);
        $term=$this->send('resources/academic_terms/'.$g['term']->id,['version'=>$g['term']->version,'reason'=>'Nullable test','absence_warning_percent'=>null],'PUT')->assertOk();$this->assertNull($term->json('absence_warning_percent'));
        $this->actor($g,'isletme_yetkilisi');
        $this->send('resources/capacity_requests',['offer_id'=>$g['offer']->id,'requested_capacity'=>-1,'reason'=>'Negatif kapasite'])->assertStatus(422);
    }

    public function test_capacity_request_requires_commission_approval_and_applies_capacity_once(): void
    {
        $g=F::graph();$this->actor($g,'isletme_yetkilisi');
        $r=$this->send('resources/capacity_requests',['offer_id'=>$g['offer']->id,'requested_capacity'=>2,'reason'=>'Sentetik kapasite incelemesi'])->assertCreated()->json();
        $this->assertSame(1,$g['offer']->refresh()->capacity);
        $r=$this->send('resources/capacity_requests/'.$r['id'].'/transition',['version'=>$r['version'],'state'=>'gonderildi'])->assertOk()->json();
        $this->send('resources/capacity_requests/'.$r['id'].'/transition',['version'=>$r['version'],'state'=>'inceleniyor'])->assertForbidden();
        $this->actor($g,'mue_komisyon');
        $r=$this->send('resources/capacity_requests/'.$r['id'].'/transition',['version'=>$r['version'],'state'=>'inceleniyor'])->assertOk()->json();
        $decision=['version'=>$r['version'],'state'=>'onaylandi','decision_no'=>'QA-'.Str::random(8),'decision_on'=>today()->toDateString(),'document_id'=>$g['doc']->id,'reason'=>'Sentetik kapasite kararı','meeting_date'=>today()->toDateString(),'members_count'=>5,'attendees_count'=>3,'votes_for'=>3,'votes_against'=>0,'chair_vote_for'=>true,'outcome'=>'onay'];
        $key=(string)Str::uuid();$this->withHeader('Idempotency-Key',$key);
        $this->postJson('/api/v1/resources/capacity_requests/'.$r['id'].'/transition',$decision)->assertOk();
        $this->postJson('/api/v1/resources/capacity_requests/'.$r['id'].'/transition',$decision)->assertOk()->assertHeader('Idempotent-Replayed','true');
        $this->assertSame(2,$g['offer']->refresh()->capacity);
        $this->assertSame(1,Records::query('decisions')->where('target_id',$r['id'])->count());
    }

    public function test_company_incident_nonconformity_and_risk_item_crud_remains_private(): void
    {
        $g=F::graph();$foreign=F::graph();
        $p=F::make('placements',array_replace(Records::scope($g['app']),['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'ilan_edildi']));
        $this->actor($g,'isletme_yetkilisi');
        $incident=$this->send('resources/incidents',['placement_id'=>$p->id,'type'=>'ramak_kala','occurred_at'=>now()->toISOString(),'location'=>'Sentetik şube','witnesses'=>[],'health_facility'=>null,'description'=>'Sentetik olay','first_response'=>'Alan kontrolü','official_notification_status'=>'yapilacak'])->assertCreated()->json();
        $nonconformity=$this->send('resources/nonconformities',['placement_id'=>$p->id,'type'=>'isg','occurred_at'=>now()->toISOString(),'description'=>'Sentetik uygunsuzluk','proposed_action'=>'Kontrol','authorized_failure'=>true])->assertCreated()->assertJsonPath('authorized_failure',false)->json();
        $risk=F::make('risk_plans',Records::scope($g['site'])+['site_id'=>$g['site']->id,'assessed_on'=>today()->toDateString(),'renewal_on'=>today()->addYear()->toDateString(),'signed_document_id'=>$g['doc']->id,'state'=>'taslak']);
        $item=$this->send('resources/risk_items',['risk_plan_id'=>$risk->id,'activity'=>'Görev','hazard'=>'Sentetik risk','possible_result'=>'Yaralanma','controls'=>'Kontrol','probability'=>2,'severity'=>2,'residual_probability'=>1,'residual_severity'=>1,'mitigation_completed'=>true,'direct_supervision'=>true,'responsible_user_id'=>$g['u']->id,'due_on'=>today()->addDay()->toDateString()])->assertCreated()->json();
        $this->send('resources/risk_items/'.$item['id'],['version'=>$item['version'],'reason'=>'Sentetik güncelleme','controls'=>'Ek kontrol'],'PUT')->assertOk()->assertJsonPath('controls','Ek kontrol');
        foreach(['incidents'=>$incident['id'],'nonconformities'=>$nonconformity['id'],'risk_items'=>$item['id']] as $type=>$id)$this->getJson('/api/v1/resources/'.$type.'/'.$id)->assertOk();
        $this->actor($foreign,'isletme_yetkilisi');
        foreach(['incidents'=>$incident['id'],'nonconformities'=>$nonconformity['id'],'risk_items'=>$item['id']] as $type=>$id){$this->getJson('/api/v1/resources/'.$type.'/'.$id)->assertForbidden();$this->getJson('/api/v1/resources/'.$type.'/'.$id.'/history')->assertForbidden();}
    }
}
