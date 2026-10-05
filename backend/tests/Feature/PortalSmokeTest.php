<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\Records;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{Queue,DB};
use Illuminate\Http\UploadedFile;

class PortalSmokeTest extends TestCase
{
    use DatabaseTransactions;
    private function role(array $g,string $role): array
    {
        $u=User::factory()->create(['password'=>'PortalTest!2026','active'=>true]);
        $data=['institution_id'=>$g['institution']->id,'user_id'=>$u->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif'];
        if($role==='bolum_komisyon')$data['program_id']=$g['prog']->id;
        if(in_array($role,['egitici','isletme_yetkilisi']))$data['company_id']=$g['company']->id;
        return [$u,F::make('role_assignments',$data)];
    }
    public function test_four_portals_login_render_and_scope(): void
    {
        $g=F::graph();$g['u']->password='PortalTest!2026';$g['u']->save();
        $this->get('/giris')->assertOk()->assertSee('Hesabınıza giriş yapın');
        $this->post('/giris',['email'=>$g['u']->email,'password'=>'yanlis'])->assertSessionHasErrors('email');
        foreach(['ogrenci'=>'Öğrenci Portalı','akademik_danisman'=>'Akademik Danışman Portalı','isletme_yetkilisi'=>'İşletme Portalı','mudur'=>'MYO Yönetim Portalı'] as $role=>$title){
            [$u,$a]=$role==='ogrenci'?[$g['u'],$g['role']]:$this->role($g,$role);
            $this->post('/giris',['email'=>$u->email,'password'=>'PortalTest!2026'])->assertRedirect('/panel');
            $this->get('/panel')->assertOk()->assertSee($title);
            $this->get('/panel/ek')->assertOk()->assertSee('portal-boot');if($role==='mudur'){$csv=$this->get('/portal-api/v1/exports/companies?format=csv')->assertOk();$this->assertStringContainsString('Ticaret unvanı',$csv->streamedContent());}
            $this->post('/cikis')->assertRedirect('/giris');
        }
        $this->actingAs($g['u'])->withSession(['assignment_id'=>$g['role']->id,'password_hash_web'=>$g['u']->password]);
        $other=F::make('students',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'user_id'=>User::factory()->create()->id,'student_no'=>'OTHER-PRIVATE','gpa_scale'=>4]);
        $this->getJson('/portal-api/v1/resources/students/'.$other->id)->assertForbidden();
        $this->get('/panel/accounts')->assertForbidden();
        $this->get('/panel/feedback')->assertOk();
        $this->getJson('/portal-api/v1/resources/feedback')->assertOk()->assertJsonCount(0,'items');
        $policy=F::make('matching_policies',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'revision'=>1,'preference_weight'=>.75,'transport_weight'=>.25,'max_preferences'=>5,'transport_rules'=>[['max_minutes'=>60,'score'=>100]],'decision_document_id'=>$g['doc']->id,'state'=>'onaylandi']);$this->getJson('/portal-api/v1/applications/'.$g['app']->id.'/preference-policy')->assertOk()->assertJsonPath('policy.preference_weight',.75)->assertJsonPath('policy.max_preferences',5)->assertJsonMissingPath('policy.decision_document_id');$this->assertSame(0,Records::query('role_assignments')->where('user_id',$g['u']->id)->where('role','!=','ogrenci')->count());
    }
    public function test_report_submission_trainer_adviser_and_document_quarantine(): void
    {
        Queue::fake();$g=F::graph();
        $p=F::make('placements',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'company_id'=>$g['company']->id,'student_id'=>$g['student']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'credited_minutes'=>0,'credited_outcomes'=>[],'state'=>'ilan_edildi']);
        [$trainer,$ta]=$this->role($g,'egitici');$g['trainer']->user_id=$trainer->id;$g['trainer']->save();
        [$adviser,$aa]=$this->role($g,'akademik_danisman');
        F::make('trainer_assignments',Records::scope($p)+['trainer_id'=>$g['trainer']->id,'valid_from'=>'2026-01-01','valid_until'=>'2027-12-31']);
        F::make('adviser_assignments',Records::scope($p)+['user_id'=>$adviser->id,'valid_from'=>'2026-01-01','valid_until'=>'2027-12-31']);
        $this->actingAs($g['u'])->withSession(['assignment_id'=>$g['role']->id,'password_hash_web'=>$g['u']->password]);
        $data=['placement_id'=>$p->id,'week_no'=>1,'week_start'=>'2026-10-01','week_end'=>'2026-10-05','unit'=>'Yazılım','activity_text'=>'Test geliştirme','knowledge_skills'=>'Sürüm kontrolü','problems_solutions'=>'Kurulum düzeltildi','revision'=>1,'trainer_review'=>'SAHTE YORUM'];
        $report=$this->withHeader('Idempotency-Key','report-create')->postJson('/portal-api/v1/resources/weekly_reports',$data)->assertCreated()->json();
        $this->assertNull($report['trainer_review']);
        $this->get('/panel/weekly_reports/'.$report['id'])->assertOk();
        $output=F::make('program_outputs',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'board_document_id'=>$g['doc']->id]);$outcome=F::make('learning_outcomes',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'program_output_id'=>$output->id]);
        $this->withHeader('Idempotency-Key','report-outcome')->postJson('/portal-api/v1/resources/report_outcomes',['report_id'=>$report['id'],'outcome_id'=>$outcome->id])->assertCreated();
        $r=$this->withHeader('Idempotency-Key','report-submit')->postJson('/portal-api/v1/resources/weekly_reports/'.$report['id'].'/transition',['version'=>$report['version'],'state'=>'gonderildi'])->assertOk()->json();
        $this->actingAs($trainer)->withSession(['assignment_id'=>$ta->id,'password_hash_web'=>$trainer->password]);
        $this->getJson('/portal-api/v1/resources/companies/'.$g['company']->id)->assertOk()->assertJsonMissingPath('iban');
        $this->getJson('/portal-api/v1/resources/students/'.$g['student']->id)->assertForbidden();$this->getJson('/portal-api/v1/placements/'.$p->id.'/student-summary')->assertOk()->assertJsonMissingPath('gpa')->assertJsonMissingPath('academic_data');
        $r=$this->withHeader('Idempotency-Key','trainer-review')->postJson('/portal-api/v1/resources/weekly_reports/'.$r['id'].'/transition',['version'=>$r['version'],'state'=>'egitici_onayi','reason'=>'Görevler incelendi.'])->assertOk()->assertJsonPath('trainer_review','Görevler incelendi.')->json();
        $this->actingAs($adviser)->withSession(['assignment_id'=>$aa->id,'password_hash_web'=>$adviser->password]);
        $this->withHeader('Idempotency-Key','adviser-review')->postJson('/portal-api/v1/resources/weekly_reports/'.$r['id'].'/transition',['version'=>$r['version'],'state'=>'danisman_onayi','reason'=>'Kazanımlarla uyumlu.'])->assertOk()->assertJsonPath('state','danisman_onayi');
        $this->actingAs($g['u'])->withSession(['assignment_id'=>$g['role']->id,'password_hash_web'=>$g['u']->password]);
        $doc=$this->withHeader('Idempotency-Key','upload-test')->post('/portal-api/v1/documents',['target_type'=>'weekly_reports','target_id'=>$r['id'],'classification'=>'kurum_ici','retention_start_event'=>'egitim','file'=>UploadedFile::fake()->image('kanit.png')],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('scan_status','bekliyor')->json();
        $this->get('/panel/documents/'.$doc['id'])->assertOk();
        $this->getJson('/portal-api/v1/documents/'.$doc['id'].'/download')->assertStatus(409)->assertJsonPath('code','DOSYA_KARANTINA');
    }
    public function test_policy_defaults_csrf_and_session_assignment_boundary(): void
    {
        $g=F::graph();[$u,$a]=$this->role($g,'bolum_komisyon');$this->actingAs($u)->withSession(['assignment_id'=>$a->id]);
        $policyDoc=F::make('documents',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'target_type'=>'programs','target_id'=>$g['prog']->id,'scan_status'=>'temiz','classification'=>'kurum_ici']);$this->withHeader('Idempotency-Key','default-policy')->postJson('/portal-api/v1/resources/matching_policies',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'revision'=>1,'decision_document_id'=>$policyDoc->id,'max_preferences'=>5,'transport_rules'=>[['max_minutes'=>60,'score'=>100]],'seed'=>'test','time_limit_seconds'=>10])->assertCreated()->assertJsonPath('preference_weight',.75)->assertJsonPath('transport_weight',.25);
        // A browser cannot replace the selected session role with a supplied API header.
        $this->actingAs($g['u'])->withSession(['assignment_id'=>$g['role']->id,'password_hash_web'=>$g['u']->password])->withHeader('X-Assignment-Id',$a->id);
        $this->getJson('/portal-api/v1/resources/matching_runs')->assertJsonCount(0,'items');
        $this->app['env']='production';
        try{$this->postJson('/portal-api/v1/notifications/'.\Illuminate\Support\Str::uuid().'/read',[])->assertStatus(419);$g['u']->forceFill(['external_subject'=>'mue-demo:test'])->save();$this->assertFalse($g['u']->allowedEnvironment());$this->postJson('/api/v1/auth/login',['email'=>$g['u']->email,'password'=>'password'])->assertUnauthorized();}
        finally{$this->app['env']='testing';}
    }
    public function test_source_correction_calendar_scope_and_structured_risk_roundtrip(): void
    {
        $g=F::graph();
        $calendar=F::make('term_programs',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'active_student_count'=>15,'semester'=>3,'grouping_enabled'=>true,'board_document_id'=>$g['doc']->id,'preference_opens_at'=>now()->subDay(),'preference_deadline'=>now()->addDays(2)]);
        $this->actingAs($g['u'])->withSession(['assignment_id'=>$g['role']->id,'password_hash_web'=>$g['u']->password]);
        $response=$this->getJson('/portal-api/v1/applications/'.$g['app']->id.'/preference-policy')->assertOk()->assertJsonPath('calendar.state','acik')->assertJsonMissingPath('calendar.board_document_id');
        $this->assertSame(\Carbon\Carbon::parse($calendar->preference_deadline)->timestamp,\Carbon\Carbon::parse($response->json('calendar.preference_deadline'))->timestamp);
        $other=F::graph();
        $this->getJson('/portal-api/v1/applications/'.$other['app']->id.'/preference-policy')->assertForbidden();
        [$user,$assignment]=$this->role($g,'isletme_yetkilisi');
        $this->actingAs($user)->withSession(['assignment_id'=>$assignment->id,'password_hash_web'=>$user->password]);
        $data=['site_id'=>$g['site']->id,'assessed_on'=>'2026-10-05','renewal_on'=>'2027-10-05','team'=>array_fill(0,5,['name'=>'Sentetik ekip üyesi','role'=>'Ekip görevi']),'general_assessment_current'=>true,
            'hazard_checklist'=>array_fill(0,16,['value'=>'Önlem gerekli','probability'=>2,'severity'=>3,'note'=>'Sentetik inceleme']),
            'emergency_scenarios'=>array_fill(0,6,['value'=>'Güvenli alana geçiş','responsible'=>'Ekip sorumlusu','notify'=>'MYO Müdürlüğü','permanent_solution'=>'EK-7 incelemesi']),
            'emergency_contacts'=>[['name'=>'Sentetik kişi','role'=>'Eğitici','phone'=>'TEST','backup'=>'Yedek ekip']],
            'nearest_exit'=>'Çıkış','assembly_area'=>'Alan','first_aid_location'=>'Revir','extinguisher_location'=>'Koridor','orientation_on'=>'2026-10-05','signed_document_id'=>$g['doc']->id];
        $result=$this->withHeader('Idempotency-Key','source-risk-create')->postJson('/portal-api/v1/resources/risk_plans',$data)->assertCreated()->json();
        $this->getJson('/portal-api/v1/resources/risk_plans/'.$result['id'])->assertOk()->assertJsonPath('hazard_checklist.0.probability',2)->assertJsonPath('emergency_scenarios.0.notify','MYO Müdürlüğü')->assertJsonPath('emergency_contacts.0.backup','Yedek ekip');
        $this->actingAs($other['u'])->withSession(['assignment_id'=>$other['role']->id,'password_hash_web'=>$other['u']->password]);
        $this->getJson('/portal-api/v1/resources/risk_plans/'.$result['id'])->assertForbidden();
    }

}


