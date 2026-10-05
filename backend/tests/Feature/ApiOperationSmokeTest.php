<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture;
use App\Domain\Records;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
class ApiOperationSmokeTest extends TestCase
{
 use DatabaseTransactions;
 public function test_authenticated_weekly_report_submission_is_scoped_and_idempotent(): void
 {
  $g=Fixture::graph();$g['u']->password='Sentetik-Parola-2026!';$g['u']->save();
  $login=$this->postJson('/api/v1/auth/login',['email'=>$g['u']->email,'password'=>'Sentetik-Parola-2026!'])->assertOk();
  $token=$login->json('token');$this->assertNotEmpty($token);
  $stored=\Laravel\Sanctum\PersonalAccessToken::findToken($token);$this->assertNotNull($stored);$this->assertFalse($stored->expires_at->isPast(),$stored->expires_at->toISOString().' / '.now()->toISOString());
  $p=Fixture::make('placements',Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>'2026-10-01','ends_on'=>'2027-01-14','state'=>'ilan_edildi']);
  $data=['placement_id'=>$p->id,'week_no'=>1,'week_start'=>'2026-10-01','week_end'=>'2026-10-07','unit'=>'Yazılım','activity_text'=>'Sentetik test faaliyeti','knowledge_skills'=>'Öğrenme kazanımı','problems_solutions'=>'Sorun ve çözüm','revision'=>1];
  $headers=['Authorization'=>'Bearer '.$token,'X-Assignment-Id'=>$g['role']->id,'Idempotency-Key'=>'weekly-fixture'];
  $this->app['auth']->forgetGuards();
  $first=$this->postJson('/api/v1/resources/weekly_reports',$data,$headers)->assertCreated();
  $again=$this->postJson('/api/v1/resources/weekly_reports',$data,$headers)->assertCreated()->assertHeader('Idempotent-Replayed','true');
  $this->assertSame($first->json('id'),$again->json('id'));
  $this->assertSame(1,Records::query('weekly_reports')->where('placement_id',$p->id)->count());
  $this->postJson('/api/v1/resources/weekly_reports',array_merge($data,['activity_text'=>'Farklı içerik']),$headers)->assertStatus(409);
  $this->getJson('/api/v1/placements/'.$p->id.'/education/devam',$headers)->assertOk()->assertJsonPath('missing_records',true);
 }
 public function test_application_eligibility_is_decided_by_the_program_commission(): void
 {
  $g=Fixture::graph();$g['app']->state='gonderildi';$g['app']->eligibility=null;$g['app']->save();
  Sanctum::actingAs($g['u']);
  $data=['version'=>$g['app']->refresh()->version,'state'=>'uygun','reason'=>'Sentetik uygunluk incelemesi'];
  $url='/api/v1/resources/applications/'.$g['app']->id.'/transition';
  $this->postJson($url,$data,['X-Assignment-Id'=>$g['role']->id,'Idempotency-Key'=>'student-eligibility'])->assertForbidden();
  $commission=Fixture::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'user_id'=>$g['u']->id,'role'=>'bolum_komisyon','valid_from'=>now()->subDay(),'state'=>'aktif']);
  $this->postJson($url,$data,['X-Assignment-Id'=>$commission->id,'Idempotency-Key'=>'commission-eligibility'])->assertOk()->assertJsonPath('eligibility','uygun');
 }
}
