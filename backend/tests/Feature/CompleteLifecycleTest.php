<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Models\User;
use App\Domain\{Records,Workflow,Matching,Education};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
class CompleteLifecycleTest extends TestCase
{
 use DatabaseTransactions;
 private array $g,$actors=[];private string $doc;
 private function actor(string $role,?string $company=null): void {
  if(!isset($this->actors[$role])) {
   $u=$role==='ogrenci'?$this->g['u']:User::factory()->create(['active'=>true]);
   $a=$role==='ogrenci'?$this->g['role']:F::make('role_assignments',['institution_id'=>$this->g['institution']->id,'user_id'=>$u->id,'role'=>$role,'program_id'=>in_array($role,['bolum_komisyon','program_baskani','belge_gorevlisi'])?$this->g['prog']->id:null,'company_id'=>in_array($role,['isletme_yetkilisi','egitici'])?$company:null,'valid_from'=>now()->subDay(),'state'=>'aktif']);$this->actors[$role]=[$u,$a];
  }
  Sanctum::actingAs($this->actors[$role][0]);$this->withHeader('X-Assignment-Id',$this->actors[$role][1]->id);
 }
 private function send(string $path,array $data=[],string $method='POST'){
  $this->withHeader('Idempotency-Key',(string)Str::uuid());return $method==='PUT'?$this->putJson('/api/v1/'.$path,$data):$this->postJson('/api/v1/'.$path,$data);
 }
 private function input(string $type,array $input): array {
  if($type==='matching_policies')$input+=['preference_weight'=>.75,'transport_weight'=>.25];
  foreach(config("domain.$type.fields") as $k=>$f)if(!array_key_exists($k,$input)&&(str_starts_with($f['rules'],'required')||str_starts_with($f['rules'],'present'))) {
   if($f['ref']){$input[$k]=$f['ref']==='documents'?$this->doc:($f['ref']==='users'?auth()->id():null);continue;}
   $v=match($f['type']){'boolean'=>true,'integer','bigint','decimal'=>1,'date'=>today()->toDateString(),'timestamp'=>now()->toISOString(),'jsonb'=>['Sentetik kayıt'],default=>'Sentetik akademik kayıt'};
   if(preg_match('/(?:^|\|)in:([^|]+)/',$f['rules'],$m)){$v=explode(',',$m[1])[0];if(in_array($f['type'],['integer','decimal']))$v=(int)$v;}
   if($f['type']==='jsonb'&&preg_match('/size:(\d+)/',$f['rules'],$m))$v=array_fill(0,(int)$m[1],80);
   if(str_contains($f['rules'],'email'))$v='sentetik@kurum.example';$input[$k]=$v;
  }
  return $input+['institution_id'=>$this->g['institution']->id];
 }
 private function create(string $type,array $data){$response=$this->send('resources/'.$type,$this->input($type,$data));$this->assertSame(201,$response->status(),$type.': '.$response->getContent());$r=$response->json();return Records::get($type,$r['id']);}
 private function step($r,string $to,array $extra=[]){$r->refresh();$rule=app(Workflow::class)->rulesFor($r->getTable(),$to);if(!in_array($this->actorsRole(),$rule['roles']))$this->actor($rule['roles'][0],$r->company_id);
  $decision=$rule['decision']?['decision_no'=>'AUDIT-'.Str::uuid(),'decision_on'=>today()->toDateString(),'meeting_date'=>today()->toDateString(),'document_id'=>$this->doc,'members_count'=>5,'attendees_count'=>3,'votes_for'=>3,'votes_against'=>0,'chair_vote_for'=>true,'outcome'=>in_array($to,['ret','reddedildi'])?'ret':'onay']:[];
  $response=$this->send('resources/'.$r->getTable().'/'.$r->id.'/transition',$extra+$decision+['version'=>$r->version,'state'=>$to,'reason'=>'Sentetik kaynak uygunluğu incelemesi']);$this->assertSame(200,$response->status(),$r->getTable().' → '.$to.': '.$response->getContent());return $r->refresh();
 }
 private function actorsRole(): string{return Records::get('role_assignments',$this->defaultHeaders['X-Assignment-Id'])->role;}
 private function approve($r){foreach(['gonderildi','inceleniyor','onaylandi'] as $to)$this->step($r,$to);return $r;}
 public function test_complete_lifecycle_with_distinct_stakeholders_and_real_optimizer(): void {
  Queue::fake();$this->g=F::graph();$this->doc=$this->g['doc']->id;$this->actor('mudur');
  $term=$this->create('academic_terms',array_merge($this->g['term']->only(array_keys(config('domain.academic_terms.fields'))),['code'=>'AUDIT-'.Str::random(8)]));
  $this->step($term,'acik');
  $this->actor('koordinator');$company=$this->create('companies',['organization_type'=>'ozel','tax_no'=>'AUDIT-'.Str::random(8),'legal_name'=>'Yaşam Döngüsü İşletmesi']);$this->step($company,'inceleniyor');$this->step($company,'aktif');
  $this->actor('isletme_yetkilisi',$company->id);$site=$this->create('company_sites',['company_id'=>$company->id,'capacity_type'=>'ortak','total_capacity'=>2,'hazard_class'=>'az_tehlikeli']);
  // Clean documents are synthetic scanner/official signature results, never real integration claims.
  $this->doc=F::make('documents',['institution_id'=>$company->institution_id,'company_id'=>$company->id,'target_type'=>'companies','target_id'=>$company->id,'classification'=>'kurum_ici','scan_status'=>'temiz'])->id;
  $this->actor('egitici',$company->id);$trainerUser=auth()->id();$this->actor('isletme_yetkilisi');
  $trainer=$this->create('trainer_qualifications',['site_id'=>$site->id,'company_id'=>$company->id,'user_id'=>$trainerUser,'degree'=>'lisans','experience_years'=>3,'national_id'=>'11111111111','phone'=>'TEST','email'=>'sentetik@kurum.example','education_records'=>[['institution'=>'Sentetik üniversite','field'=>'Bilgisayar','degree'=>'lisans','graduation_year'=>2020]],'experience_records'=>[['institution'=>'Sentetik işletme','duty'=>'Eğitici','started_on'=>'2020-01-01','duration'=>'6 yıl']]]);$this->approve($trainer);
  $this->actor('koordinator');$assessment=$this->create('company_assessments',['site_id'=>$site->id,'company_id'=>$company->id,'program_id'=>$this->g['prog']->id,'trainer_id'=>$trainer->id,'valid_from'=>'2026-01-01','valid_until'=>'2028-01-01']);$this->approve($assessment);
  $this->actor('koordinator');$protocol=$this->create('protocols',['company_id'=>$company->id,'signed_on'=>'2026-01-01','valid_from'=>'2026-01-01','valid_until'=>'2029-01-01']);$this->step($protocol,'imza_bekliyor');$this->step($protocol,'aktif');
  $this->actor('koordinator');$this->create('protocol_scopes',['protocol_id'=>$protocol->id,'site_id'=>$site->id,'program_id'=>$this->g['prog']->id]);
  $offer=$this->create('offers',['term_id'=>$term->id,'program_id'=>$this->g['prog']->id,'company_id'=>$company->id,'site_id'=>$site->id,'protocol_id'=>$protocol->id,'trainer_id'=>$trainer->id,'assessment_id'=>$assessment->id,'capacity'=>1,'interview_required'=>true]);$this->step($offer,'dogrulandi');$this->step($offer,'onaylandi');$this->step($offer,'ilan_edildi');
  $this->actor('bolum_komisyon');$policy=$this->create('matching_policies',['term_id'=>$term->id,'program_id'=>$this->g['prog']->id,'max_preferences'=>5,'transport_rules'=>[['max_minutes'=>30,'score'=>100],['score'=>20]],'seed'=>'lifecycle-2026','time_limit_seconds'=>10]);$this->approve($policy);
  $this->actor('ogrenci');$app=$this->create('applications',['student_id'=>$this->g['student']->id,'term_id'=>$term->id]);$this->step($app,'gonderildi');$this->step($app,'uygun');
  $this->actor('ogrenci');$prefs=$this->send('applications/'.$app->id.'/preferences',['version'=>$app->refresh()->version,'preferences'=>[['offer_id'=>$offer->id,'reachable'=>true,'one_way_minutes'=>20]]],'PUT')->assertOk()->json();
  $this->send('applications/'.$app->id.'/submit-preferences',['version'=>$prefs['application_version'],'revision'=>1])->assertOk();
  $this->actor('bolum_komisyon');$this->send('applications/'.$app->id.'/verify-transport',['version'=>$app->refresh()->version,'revision'=>1,'reason'=>'Ulaşım incelendi'])->assertOk();
  $this->actor('isletme_yetkilisi');$interview=$this->create('interviews',['application_id'=>$app->id,'offer_id'=>$offer->id,'result'=>'kabul']);$this->approve($interview);
  $this->actor('mudur');$this->send('resources/academic_terms/'.$term->id,['version'=>$term->version,'preference_deadline'=>now()->subMinute()->toISOString(),'reason'=>'Sentetik tercih takvimi kapatıldı'],'PUT')->assertOk();
  $this->actor('bolum_komisyon');$runId=$this->send('matching-runs',['policy_id'=>$policy->id])->assertStatus(202)->json('run_id');(new \App\Jobs\RunMatching($runId))->handle(app(Matching::class));$run=Records::get('matching_runs',$runId);$this->assertSame('tamamlandi',$run->state);
  $recommended=$this->send('matching-runs/'.$runId.'/recommendations')->assertOk()->json('placements.0.id');$p=Records::get('placements',$recommended);$this->step($p,'bolum_incelemesi');$this->step($p,'onaylandi');
  $this->actor('mudur');$this->send('publications',['term_id'=>$term->id,'targets'=>[['type'=>'placements','id'=>$p->id]],'decision_document_id'=>$this->doc])->assertCreated();
  $this->actor('akademik_danisman');$adviserUser=auth()->id();$this->actor('bolum_komisyon');$this->create('adviser_assignments',['student_id'=>$p->student_id,'term_id'=>$p->term_id,'user_id'=>$adviserUser,'valid_from'=>$p->starts_on,'valid_until'=>$p->ends_on]);$this->create('trainer_assignments',['placement_id'=>$p->id,'trainer_id'=>$trainer->id,'valid_from'=>$p->starts_on,'valid_until'=>$p->ends_on]);
  $this->doc=F::make('documents',Records::scope($p)+['target_type'=>'placements','target_id'=>$p->id,'classification'=>'kurum_ici','scan_status'=>'temiz'])->id;
  $this->actor('belge_gorevlisi');$insurance=$this->create('insurance_records',['placement_id'=>$p->id,'starts_on'=>$p->starts_on,'ends_on'=>$p->ends_on]);$this->approve($insurance);
  $this->actor('egitici');$ohs=$this->create('ohs_records',['placement_id'=>$p->id,'trained_on'=>$p->starts_on,'ppe_items'=>['Baret']]);$this->approve($ohs);
  $this->actor('ogrenci');foreach(['EK-8','EK-10'] as $form)$this->create('declarations',['placement_id'=>$p->id,'form_code'=>$form,'text_version'=>config('forms.version')]);
  $this->assertSame([],app(\App\Domain\Eligibility::class)->ready($p->refresh()));$this->step($p,'baslamaya_hazir');$this->step($p,'basladi');
  $this->actor('bolum_komisyon');$output=$this->create('program_outputs',['program_id'=>$p->program_id,'code'=>'PÇ-AUDIT','target_percent'=>70]);$outcome=$this->create('learning_outcomes',['program_output_id'=>$output->id,'code'=>'ÖK-AUDIT']);$rubric=$this->create('rubric_versions',['program_id'=>$p->program_id,'code'=>'R-AUDIT','levels'=>array_map(fn($n)=>['name'=>'Düzey '.$n,'behavior'=>'Gözlenebilir davranış '.$n],range(1,4))]);$this->approve($rubric);
  $risk=F::make('risk_plans',['institution_id'=>$p->institution_id,'company_id'=>$company->id,'site_id'=>$site->id,'assessed_on'=>'2026-01-01','renewal_on'=>'2027-01-01','signed_document_id'=>$this->doc,'state'=>'onaylandi']);$riskItem=F::make('risk_items',Records::scope($risk)+['risk_plan_id'=>$risk->id,'probability'=>1,'severity'=>2,'residual_probability'=>1,'residual_severity'=>2,'responsible_user_id'=>$trainerUser]);
  $this->actor('akademik_danisman');$plan=$this->create('learning_plans',['placement_id'=>$p->id,'revision'=>1,'prepared_on'=>'2026-10-05']);$this->create('plan_outcomes',['plan_id'=>$plan->id,'outcome_id'=>$outcome->id,'evidence_sources'=>['Haftalık rapor','Eğitici gözlemi'],'planned_weeks'=>range(1,15)]);
  foreach(range(1,15) as $w)$this->create('weekly_plan_tasks',['placement_id'=>$p->id,'plan_id'=>$plan->id,'outcome_id'=>$outcome->id,'risk_item_id'=>$riskItem->id,'week_no'=>$w,'expected_hours'=>40]);$this->approve($plan);
  foreach(range(1,15) as $w){$this->actor('ogrenci');$start=\Carbon\Carbon::parse($p->starts_on)->addWeeks($w-1);$report=$this->create('weekly_reports',['placement_id'=>$p->id,'week_no'=>$w,'week_start'=>$start->toDateString(),'week_end'=>$start->addDays(6)->toDateString(),'revision'=>1]);$this->create('report_outcomes',['report_id'=>$report->id,'outcome_id'=>$outcome->id]);$this->step($report,'gonderildi');$this->step($report,'egitici_onayi');$this->step($report,'danisman_onayi');}
  $this->actor('ogrenci');$evidence=$this->create('portfolio_evidence',['placement_id'=>$p->id,'outcome_id'=>$outcome->id,'evidence_type'=>'rapor','protected_material'=>false]);$this->approve($evidence);
  $date=\Carbon\Carbon::parse($p->starts_on);$days=0;
  while($days<75){if($date->isWeekday()){$this->actor('egitici');$a=$this->create('attendance',['placement_id'=>$p->id,'date'=>$date->toDateString(),'planned_minutes'=>480,'attended_minutes'=>480,'mark'=>'V']);$this->approve($a);$days++;}$date->addDay();}
  $this->actor('akademik_danisman');foreach(range(1,15) as $w){$i=$this->create('inspections',['placement_id'=>$p->id,'inspected_at'=>\Carbon\Carbon::parse($p->starts_on)->addWeeks($w-1)->toISOString(),'mode'=>'yuz_yuze','workplace_inspection'=>$w<=2]);$this->approve($i);}
  foreach(['is_yeri','danisman','dosya_portfolyo','sunum'] as $source)foreach(['mesleki','problem','takim','etik','isg','belgeleme'] as $area){$this->actor($source==='is_yeri'?'egitici':'akademik_danisman');$evaluation=$this->create('rubric_evaluations',['placement_id'=>$p->id,'rubric_id'=>$rubric->id,'outcome_id'=>$outcome->id,'source'=>$source,'area'=>$area,'criterion_values'=>[80],'revision'=>1,'workplace_criteria_scores'=>$source==='is_yeri'?array_fill(0,12,80):null]);$this->approve($evaluation);}
  $presentation=$this->create('presentations',['placement_id'=>$p->id,'presented_on'=>$p->ends_on]);$this->approve($presentation);
  $this->travelTo(\Carbon\Carbon::parse($p->ends_on));$this->actor('ogrenci');$self=$this->create('self_assessments',['placement_id'=>$p->id,'outcome_id'=>$outcome->id,'phase'=>'donem_sonu','transferable_competencies'=>array_fill(0,10,['value'=>3]),'reflection_answers'=>array_fill(0,5,'Sentetik gelişim ve kazanım açıklaması'),'portfolio_checklist'=>array_fill(0,13,['value'=>true])]);$this->step($self,'gonderildi');$this->step($self,'inceleniyor');$this->step($self,'onaylandi',['portfolio_integrity'=>'yeterli','evidence_consistency'=>'tutarli','adviser_feedback'=>'Kanıtlar kazanımlarla uyumlu.']);
  $this->actor('ogrenci');$delivery=$this->create('training_file_deliveries',['placement_id'=>$p->id,'paper_delivered_on'=>$p->ends_on,'electronic_delivered_on'=>$p->ends_on]);$this->approve($delivery);
  $this->actor('akademik_danisman');$resultId=$this->send('placements/'.$p->id.'/calculate-success')->assertCreated()->assertJsonPath('total_score',80)->assertJsonPath('outcome','basarili')->json('id');$result=Records::get('success_results',$resultId);$this->step($result,'onaylandi');
  $this->actor('mudur');$this->send('publications',['term_id'=>$term->id,'targets'=>[['type'=>'success_results','id'=>$result->id]],'decision_document_id'=>$this->doc])->assertCreated();
  $this->actor('ogrenci');$appealId=$this->send('appeals',['target_type'=>'success_results','target_id'=>$result->id,'reason'=>'Sentetik puan inceleme istemi'])->assertCreated()->json('id');$this->assertTrue($result->refresh()->legal_hold);$appeal=Records::get('appeals',$appealId);$this->step($appeal,'inceleniyor');$this->step($appeal,'ret');$this->assertFalse($result->refresh()->legal_hold);
  $this->assertTrue(app(Education::class)->monitoring($p)['weekly_requirement_met']);$this->step($p,'tamamlandi');$this->assertSame(75,Records::query('attendance')->where('placement_id',$p->id)->count());
  $this->assertCount(9,$this->actors);$this->assertGreaterThan(0,\Illuminate\Support\Facades\DB::table('audit_events')->where('institution_id',$p->institution_id)->whereNotNull('assignment_id')->count());
  $this->travelBack();
 }
}
