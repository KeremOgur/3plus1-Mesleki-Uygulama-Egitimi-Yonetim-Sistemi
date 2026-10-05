<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\{Records,Matching,Eligibility,Education,EducationModel,DomainError};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Queue,Storage};
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
class SourceComplianceTest extends TestCase
{
 use DatabaseTransactions;
 private function login(array $g,string $role='ogrenci',array $scope=[]): void {
  Sanctum::actingAs($g['u']);
  $a=$role==='ogrenci'?$g['role']:F::make('role_assignments',$scope+['institution_id'=>$g['institution']->id,'user_id'=>$g['u']->id,'program_id'=>in_array($role,['bolum_komisyon','program_baskani'])?$g['prog']->id:null,'company_id'=>in_array($role,['isletme_yetkilisi','egitici'])?$g['company']->id:null,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif']);
  $this->withHeaders(['X-Assignment-Id'=>$a->id,'Idempotency-Key'=>(string)\Illuminate\Support\Str::uuid()]);
 }
 private function policy(array $g,array $extra=[]){return F::make('matching_policies',$extra+['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'revision'=>1,'preference_weight'=>.75,'transport_weight'=>.25,'max_preferences'=>10,'transport_rules'=>[['max_minutes'=>30,'score'=>100],['score'=>20]],'seed'=>'source-audit','time_limit_seconds'=>10,'decision_document_id'=>$g['doc']->id,'state'=>'onaylandi']);}
 private function placement(array $g,array $extra=[]){return F::make('placements',$extra+Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'ilan_edildi']);}
 public function test_optional_requested_interview_blocks_only_its_pair(): void {
  $g=F::graph();$e=app(Eligibility::class);$this->assertTrue($e->pair($g['app'],$g['offer'])['eligible']);
  $i=F::make('interviews',Records::scope($g['pref'])+['application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'result'=>'bekliyor']);
  $this->assertFalse($e->pair($g['app'],$g['offer'])['eligible']);$i->result='kabul';$i->save();$this->assertTrue($e->pair($g['app'],$g['offer'])['eligible']);
  $site=F::make('company_sites',Records::scope($g['site'])+['name'=>'İkinci şube','capacity_type'=>'ortak','total_capacity'=>2,'hazard_class'=>'az_tehlikeli']);
  $trainer=F::make('trainer_qualifications',['site_id'=>$site->id]+Records::scope($g['trainer'])+$g['trainer']->only(array_keys(config('domain.trainer_qualifications.fields')))+['state'=>'onaylandi']);
  $assessment=F::make('company_assessments',['site_id'=>$site->id,'trainer_id'=>$trainer->id]+Records::scope($g['assessment'])+$g['assessment']->only(array_keys(config('domain.company_assessments.fields')))+['state'=>'onaylandi']);
  F::make('protocol_scopes',Records::scope($g['assessment'])+['site_id'=>$site->id,'protocol_id'=>$g['protocol']->id]);
  $other=F::make('offers',['site_id'=>$site->id,'trainer_id'=>$trainer->id,'assessment_id'=>$assessment->id]+Records::scope($g['offer'])+$g['offer']->only(array_keys(config('domain.offers.fields')))+['state'=>'ilan_edildi']);
  $i->result='ret';$i->reason='Program etkinliği uygun değil';$i->save();$this->assertFalse($e->pair($g['app'],$g['offer'])['eligible']);
  F::make('preferences',Records::scope($g['pref'])+['application_id'=>$g['app']->id,'offer_id'=>$other->id,'revision'=>1,'rank'=>2,'one_way_minutes'=>20,'reachable'=>true,'transport_verified'=>true,'submitted_at'=>now()]);
  $this->assertTrue($e->pair($g['app'],$other)['eligible']);
 }
 public function test_snapshot_detects_new_candidates_and_missing_transport_is_not_zero(): void {
  $g=F::graph();$m=app(Matching::class);$p=$this->policy($g,['transport_rules'=>[['max_minutes'=>10,'score'=>100]]]);
  $s=$m->snapshot($p);$this->assertNull($s['candidates'][0]['score']);$this->assertFalse($s['candidates'][0]['eligible']);
  $run=F::make('matching_runs',Records::scope($p)+['policy_id'=>$p->id,'snapshot'=>$s,'snapshot_hash'=>$m->hash($s),'algorithm_version'=>$s['algorithm_version'],'state'=>'tamamlandi']);$m->assertFresh($run);
  F::make('interviews',Records::scope($g['pref'])+['application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'result'=>'bekliyor']);
  $this->expectException(DomainError::class);$m->assertFresh($run);
 }
 public function test_preferences_cannot_inject_scope_verification_or_submission_and_admin_correction_is_versioned(): void {
  $g=F::graph();$this->policy($g);$this->login($g);
  $payload=['version'=>$g['app']->version,'preferences'=>[['offer_id'=>$g['offer']->id,'one_way_minutes'=>20,'reachable'=>true,'transport_verified'=>true,'submitted_at'=>now(),'student_id'=>\Illuminate\Support\Str::uuid(),'rank'=>99]]];
  $r=$this->putJson('/api/v1/applications/'.$g['app']->id.'/preferences',$payload)->assertOk()->assertJsonPath('preferences.0.transport_verified',false)->assertJsonPath('preferences.0.submitted_at',null)->assertJsonPath('preferences.0.rank',1)->assertJsonPath('preferences.0.student_id',$g['student']->id)->json();
  $this->withHeader('Idempotency-Key','stale-pref')->putJson('/api/v1/applications/'.$g['app']->id.'/preferences',$payload)->assertStatus(409)->assertJsonPath('code','STALE_VERSION');
  $g['term']->preference_deadline=now()->subDay();$g['term']->save();$this->login($g,'bolum_komisyon');
  $payload['version']=$r['application_version'];$payload['reason']='Belgelenmiş ulaşım düzeltmesi';
  $this->postJson('/api/v1/applications/'.$g['app']->id.'/correct-preferences',$payload)->assertOk()->assertJsonPath('revision',3)->assertJsonPath('preferences.0.transport_verified',true);
  $this->assertSame(3,Records::query('preferences')->where('application_id',$g['app']->id)->count());
 }
 public function test_profile_confirmation_cannot_replace_institutional_academic_fields(): void {
  $g=F::graph();$this->login($g);$this->postJson('/api/v1/students/'.$g['student']->id.'/confirm-profile',['version'=>$g['student']->version,'phone'=>'TEST','gpa'=>0,'student_no'=>'SAHTE'])->assertOk()->assertJsonPath('gpa',3.2)->assertJsonPath('student_no',$g['student']->student_no);$this->assertNotNull($g['student']->refresh()->profile_confirmed_at);
 }
 public function test_company_and_student_cannot_download_unrelated_or_secret_company_documents(): void {
  $g=F::graph();$this->login($g);$this->getJson('/api/v1/resources/documents/'.$g['doc']->id)->assertForbidden();$this->getJson('/api/v1/documents/'.$g['doc']->id.'/download')->assertForbidden();
  $other=F::graph();$this->login($g,'isletme_yetkilisi');$this->getJson('/api/v1/resources/companies/'.$other['company']->id)->assertForbidden();$this->getJson('/api/v1/documents/'.$other['doc']->id.'/download')->assertForbidden();
 }
 public function test_approved_parent_child_is_immutable_even_at_database_boundary(): void {
  $g=F::graph();$p=$this->placement($g);$report=F::make('weekly_reports',Records::scope($p)+['revision'=>1,'state'=>'danisman_onayi']);
  try {DB::transaction(fn()=>F::make('report_outcomes',Records::scope($report)+['report_id'=>$report->id]));$this->fail('Child insert accepted');}catch(\Illuminate\Database\QueryException $e){$this->assertSame('P0001',$e->getCode());}
 }
 public function test_monitoring_survives_workplace_change(): void {
  $g=F::graph();$old=$this->placement($g,['ends_on'=>'2026-11-01','state'=>'degistirildi']);
  $new=$this->placement($g,['starts_on'=>'2026-11-02','previous_placement_id'=>$old->id]);
  foreach(range(1,15) as $w){$date=\Carbon\Carbon::parse('2026-10-01')->addWeeks($w-1);$p=$date->toDateString()<'2026-11-02'?$old:$new;F::make('inspections',Records::scope($p)+['inspected_at'=>$date,'mode'=>'yuz_yuze','workplace_inspection'=>$w<=2,'document_id'=>$g['doc']->id,'state'=>'onaylandi']);}
  $summary=app(Education::class)->monitoring($new);$this->assertTrue($summary['weekly_requirement_met']);$this->assertTrue($summary['workplace_requirement_met']);
 }
 public function test_approved_15_boundary_is_nonoverlapping(): void {
  $g=F::graph();$tp=F::make('term_programs',Records::scope($g['app'])+['board_document_id'=>$g['doc']->id,'active_student_count'=>14,'grouping_enabled'=>false,'semester'=>4]);
  $this->assertSame(4,app(EducationModel::class)->semester($g['student'],$tp)['semester']);$tp->active_student_count=15;$tp->semester=3;$tp->save();$this->assertSame(3,app(EducationModel::class)->semester($g['student'],$tp)['semester']);
 }
 public function test_unsafe_upload_and_quarantine_fail_closed(): void {
  Queue::fake();$g=F::graph();$this->login($g);
  $this->postJson('/api/v1/documents',['target_type'=>'students','target_id'=>$g['student']->id,'classification'=>'kurum_ici','retention_start_event'=>'egitim','file'=>UploadedFile::fake()->createWithContent('zararli.pdf','<?php echo 1;')])->assertStatus(422);
  $this->withHeader('Idempotency-Key','safe-quarantine')->postJson('/api/v1/documents',['target_type'=>'students','target_id'=>$g['student']->id,'classification'=>'kurum_ici','retention_start_event'=>'egitim','file'=>UploadedFile::fake()->image('kanit.png')])->assertCreated()->assertJsonPath('scan_status','bekliyor')->assertJsonMissingPath('object_key');
 }
 public function test_generic_appeal_cannot_bypass_deadlines(): void {
  $g=F::graph();$p=$this->placement($g);$this->login($g);$this->postJson('/api/v1/resources/appeals',['target_type'=>'placements','target_id'=>$p->id,'reason'=>'İtiraz'])->assertStatus(422)->assertJsonPath('code','OZEL_SUREC');
  $g['term']->placement_appeal_deadline=now()->subDay();$g['term']->save();$this->withHeader('Idempotency-Key','late-appeal')->postJson('/api/v1/appeals',['target_type'=>'placements','target_id'=>$p->id,'reason'=>'İtiraz'])->assertStatus(409)->assertJsonPath('code','DEADLINE_PASSED');
 }
}
