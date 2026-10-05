<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\Mfa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
class MfaTest extends TestCase
{
 use DatabaseTransactions;
 public function test_rfc6238_sha1_vector(): void {$this->assertSame('287082',app(Mfa::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',1));}
 public function test_privileged_production_login_fails_until_enrollment_and_rejects_replay(): void {
  $g=F::graph();F::make('role_assignments',['institution_id'=>$g['institution']->id,'user_id'=>$g['u']->id,'role'=>'mue_komisyon','valid_from'=>now()->subDay(),'state'=>'aktif']);$this->app['env']='production';
  $password='Sentetik.Test.456';$g['u']->password=$password;$g['u']->save();$credentials=['email'=>$g['u']->email,'password'=>$password];
  $this->postJson('/api/v1/auth/login',$credentials)->assertStatus(503)->assertJsonPath('code','MFA_KURULUMU')->assertJsonMissingPath('token');
  $g['u']->mfa_secret=app(Mfa::class)->secret();$g['u']->mfa_confirmed_at=now();$g['u']->save();$this->postJson('/api/v1/auth/login',$credentials)->assertUnauthorized()->assertJsonPath('code','MFA_GEREKLI');
  $code=app(Mfa::class)->code($g['u']->mfa_secret,intdiv(now()->timestamp,30));$credentials['otp']=$code;$this->postJson('/api/v1/auth/login',$credentials)->assertOk()->assertJsonStructure(['token']);$this->postJson('/api/v1/auth/login',$credentials)->assertUnauthorized()->assertJsonPath('code','MFA_GEREKLI');
  $this->assertStringNotContainsString($g['u']->mfa_secret,\Illuminate\Support\Facades\DB::table('users')->where('id',$g['u']->id)->value('mfa_secret'));$this->assertArrayNotHasKey('mfa_secret',$g['u']->toArray());
 }
}
