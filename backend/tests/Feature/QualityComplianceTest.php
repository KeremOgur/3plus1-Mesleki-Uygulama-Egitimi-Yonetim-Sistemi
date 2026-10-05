<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\Records;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
class QualityComplianceTest extends TestCase
{
 use DatabaseTransactions;
 private function actor(array $g,string $role): void {$a=F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>in_array($role,['bolum_komisyon','belge_gorevlisi'])?$g['prog']->id:null,'user_id'=>$g['u']->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif']);Sanctum::actingAs($g['u']);$this->withHeaders(['X-Assignment-Id'=>$a->id,'Idempotency-Key'=>bin2hex(random_bytes(8))]);}
 private function decision(array $g,array $extra=[]): array {return $extra+['decision_no'=>'TEST-'.bin2hex(random_bytes(4)),'decision_on'=>today()->toDateString(),'document_id'=>$g['doc']->id,'reason'=>'Sentetik kaynak denetimi','meeting_date'=>today()->toDateString(),'members_count'=>3,'attendees_count'=>3,'votes_for'=>3,'votes_against'=>0,'chair_vote_for'=>true,'outcome'=>'onay'];}
 public function test_online_permission_requires_documented_department_proposal(): void {
  $g=F::graph();$p=F::make('placements',Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'ilan_edildi']);
  $this->actor($g,'mue_komisyon');$this->postJson('/api/v1/placements/'.$p->id.'/online-permission',$this->decision($g,['version'=>$p->version]))->assertStatus(422)->assertJsonPath('code','BOLUM_ONERISI');
  $this->actor($g,'bolum_komisyon');$this->postJson('/api/v1/placements/'.$p->id.'/online-proposal',$this->decision($g,['version'=>$p->version]))->assertCreated()->assertJsonPath('stage','bolum');
  $this->actor($g,'mue_komisyon');$this->postJson('/api/v1/placements/'.$p->id.'/online-permission',$this->decision($g,['version'=>$p->refresh()->version]))->assertOk();$this->assertNotNull($p->refresh()->online_decision_id);
 }
 public function test_annual_rejected_board_cannot_be_used_as_approval(): void {
  $g=F::graph();$output=F::make('program_outputs',Records::scope($g['prog'])+['target_percent'=>85,'board_document_id'=>$g['doc']->id]);$annual=F::make('annual_reports',Records::scope($g['app'])+['state'=>'inceleniyor','outcome_attainment'=>[['program_output_id'=>$output->id,'students_above_60_percent'=>90]]]);
  $this->actor($g,'mudur');$this->postJson('/api/v1/board-decisions/annual_reports/'.$annual->id,$this->decision($g,['version'=>$annual->version,'outcome'=>'ret']))->assertCreated();$this->assertNull($annual->refresh()->board_document_id);
  $this->actor($g,'mue_komisyon');$this->postJson('/api/v1/resources/annual_reports/'.$annual->id.'/transition',['version'=>$annual->version,'state'=>'onaylandi'])->assertStatus(409)->assertJsonPath('code','KURUL_KARARI');
  $this->actor($g,'mudur');$this->postJson('/api/v1/board-decisions/annual_reports/'.$annual->id,$this->decision($g,['version'=>$annual->version]))->assertCreated();
  $this->actor($g,'mue_komisyon');$this->postJson('/api/v1/resources/annual_reports/'.$annual->id.'/transition',['version'=>$annual->refresh()->version,'state'=>'onaylandi'])->assertOk();
 }
 public function test_output_report_includes_students_without_evaluations(): void {
  $g=F::graph();F::make('placements',Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'ilan_edildi']);
  $output=F::make('program_outputs',Records::scope($g['prog'])+['target_percent'=>85,'board_document_id'=>$g['doc']->id]);F::make('learning_outcomes',Records::scope($g['prog'])+['program_output_id'=>$output->id]);$this->actor($g,'bolum_komisyon');
  $this->getJson('/api/v1/reports/outcomes?term_id='.$g['term']->id)->assertOk()->assertJsonPath('learning_outcomes.0.incomplete_students',1)->assertJsonPath('program_outputs.0.target_percent',85)->assertJsonPath('program_outputs.0.above_60_percent',null);
 }
 public function test_feedback_writer_reads_only_own_feedback(): void {
  $g=F::graph();$other=F::make('feedback',Records::scope($g['app'])+['created_by'=>\App\Models\User::factory()->create()->id,'source_role'=>'ogrenci']);$own=F::make('feedback',Records::scope($g['app'])+['created_by'=>$g['u']->id,'source_role'=>'ogrenci']);Sanctum::actingAs($g['u']);$this->withHeader('X-Assignment-Id',$g['role']->id);$this->getJson('/api/v1/resources/feedback/'.$own->id)->assertOk();$this->getJson('/api/v1/resources/feedback/'.$other->id)->assertForbidden();
 }
 public function test_inspection_action_can_be_created_tracked_and_closed_with_evidence(): void {
  $g=F::graph();$p=F::make('placements',Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'ilan_edildi']);F::make('adviser_assignments',Records::scope($g['app'])+['user_id'=>$g['u']->id,'valid_from'=>today()->subDay(),'valid_until'=>today()->addYear()]);$inspection=F::make('inspections',Records::scope($p)+['document_id'=>$g['doc']->id]);$this->actor($g,'akademik_danisman');
  $data=['inspection_id'=>$inspection->id,'owner_user_id'=>$g['u']->id,'finding'=>'İSG kayıtları eksik','action'=>'Oryantasyon kayıtlarını tamamla','root_cause'=>'Teslim gecikmesi','due_on'=>today()->addWeek()->toDateString(),'resource_need'=>'Eğitici görüşmesi','indicator'=>'Tamamlanan kayıt sayısı'];
  $action=$this->postJson('/api/v1/resources/improvement_actions',$data)->assertCreated()->assertJsonPath('state','acik')->json();$id=$action['id'];$this->getJson('/api/v1/resources/improvement_actions/'.$id)->assertOk();
  foreach(['uygulaniyor','kontrol_edildi'] as $state){$this->withHeader('Idempotency-Key',bin2hex(random_bytes(8)));$action=$this->postJson('/api/v1/resources/improvement_actions/'.$id.'/transition',['state'=>$state,'version'=>$action['version']])->assertOk()->json();}
  $this->withHeader('Idempotency-Key','no-action-evidence');$this->postJson('/api/v1/resources/improvement_actions/'.$id.'/transition',['state'=>'kapandi','version'=>$action['version']])->assertStatus(422)->assertJsonPath('code','ETKI_KANITI');
  $this->withHeader('Idempotency-Key','action-evidence');$action=$this->putJson('/api/v1/resources/improvement_actions/'.$id,['version'=>$action['version'],'reason'=>'Etkisi doğrulandı','effect_evidence'=>'Oryantasyon belgeleri tamamlandı','remeasured_value'=>1])->assertOk()->json();$this->withHeader('Idempotency-Key','close-action');$this->postJson('/api/v1/resources/improvement_actions/'.$id.'/transition',['state'=>'kapandi','version'=>$action['version']])->assertOk()->assertJsonPath('state','kapandi');
 }
 public function test_company_financial_and_nonconformity_reader_permissions_remain_company_scoped(): void {
  $g=F::graph();$other=F::graph();$this->actor($g,'isletme_yetkilisi');$a=Records::query('role_assignments')->where('id',$this->defaultHeaders['X-Assignment-Id'])->first();$a->company_id=$g['company']->id;$a->save();request()->headers->set('X-Assignment-Id',$a->id);
  F::make('placements',array_replace(Records::scope($g['app']),['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'ilan_edildi']));
  foreach(['payroll','fund_contributions','nonconformities'] as $type){$own=\App\Models\DomainRecord::for($type)->fill(array_replace(Records::scope($g['app']),['company_id'=>$g['company']->id]));$foreign=\App\Models\DomainRecord::for($type)->fill(array_replace(Records::scope($other['app']),['company_id'=>$other['company']->id]));$this->assertContains('isletme_yetkilisi',config("domain.$type.read"));$this->assertTrue(app(\App\Domain\ScopeAccess::class)->can($g['u'],'view',$own),$type);$this->assertFalse(app(\App\Domain\ScopeAccess::class)->can($g['u'],'view',$foreign));}
 }
}
