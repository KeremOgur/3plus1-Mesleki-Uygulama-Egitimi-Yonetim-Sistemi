<?php
namespace Tests\Feature;

use App\Domain\Records;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixture as F;
use Tests\TestCase;

class AuthRoleRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public static function roles(): array
    {
        return array_combine($roles=['ogrenci','akademik_danisman','isletme_yetkilisi','egitici','mudur','mue_komisyon','bolum_komisyon','sistem_yoneticisi'],array_map(fn($role)=>[$role],$roles));
    }

    #[DataProvider('roles')]
    public function test_each_role_login_wrong_password_session_assignment_scope_and_logout(string $role): void
    {
        $g=F::graph();$g['u']->password='Regression!2026';$g['u']->save();
        $a=$role==='ogrenci'?$g['role']:F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$role==='bolum_komisyon'?$g['prog']->id:null,'company_id'=>in_array($role,['egitici','isletme_yetkilisi'])?$g['company']->id:null,'user_id'=>$g['u']->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif']);
        $foreign=F::graph();
        $this->post('/giris',['email'=>$g['u']->email,'password'=>'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $login=$this->post('/giris',['email'=>$g['u']->email,'password'=>'Regression!2026']);
        $login->assertRedirect($role==='ogrenci'?'/panel':'/gorev');
        $this->assertAuthenticatedAs($g['u'],'web');
        $this->post('/gorev',['assignment_id'=>$foreign['role']->id])->assertForbidden();
        $this->post('/gorev',['assignment_id'=>$a->id])->assertRedirect('/panel')->assertSessionHas('assignment_id',$a->id);
        $this->get('/panel')->assertOk();
        $this->get('/panel/ek')->assertOk();
        $this->getJson('/portal-api/v1/schema')->assertOk();
        if($role==='sistem_yoneticisi'){
            // Technical administrators manage institutions globally, but do not
            // gain access to academic student records through that permission.
            $this->getJson('/portal-api/v1/resources/students/'.$foreign['student']->id)->assertForbidden();
            $this->get('/panel/students/'.$foreign['student']->id)->assertForbidden();
        }else{
            $this->getJson('/portal-api/v1/resources/institutions/'.$foreign['institution']->id)->assertForbidden();
            $this->get('/panel/institutions/'.$foreign['institution']->id)->assertForbidden();
        }
        $this->post('/cikis')->assertRedirect('/giris');$this->assertGuest('web');
        $this->get('/panel')->assertRedirect('/giris');
        $this->getJson('/portal-api/v1/schema')->assertUnauthorized();
    }

    public function test_api_bearer_logout_revokes_token_and_foreign_assignment_is_rejected(): void
    {
        $g=F::graph();$foreign=F::graph();$g['u']->password='Regression!2026';$g['u']->save();
        $auth=$this->postJson('/api/v1/auth/login',['email'=>$g['u']->email,'password'=>'Regression!2026'])->assertOk()->json();
        $this->assertSame([$g['role']->id],array_column($auth['assignments'],'id'));
        $this->withHeaders(['Authorization'=>'Bearer '.$auth['token'],'X-Assignment-Id'=>$foreign['role']->id]);
        $this->getJson('/api/v1/resources/students')->assertForbidden();
        $this->withHeader('X-Assignment-Id',$g['role']->id)->getJson('/api/v1/resources/students')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id',$g['u']->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0,$g['u']->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_failed_password_rate_limit_is_controlled(): void
    {
        $user=User::factory()->create(['active'=>true,'password'=>'Regression!2026']);
        foreach(range(1,5) as $i)$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Regression!2026'])->assertStatus(429)->assertJsonPath('code','GIRIS_SINIRI');
        $this->assertSame(0,$user->tokens()->count());
    }

    #[DataProvider('roles')]
    public function test_all_domain_lists_portal_render_and_empty_create_follow_role_matrix(string $role): void
    {
        $g=F::graph();
        $a=$role==='ogrenci'?$g['role']:F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$role==='bolum_komisyon'?$g['prog']->id:null,'company_id'=>in_array($role,['egitici','isletme_yetkilisi'])?$g['company']->id:null,'user_id'=>$g['u']->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif']);
        $this->actingAs($g['u'],'web')->withSession(['assignment_id'=>$a->id,'password_hash_web'=>$g['u']->password]);
        foreach(config('domain') as $type=>$spec){
            $list=$this->getJson('/portal-api/v1/resources/'.$type.'?limit=2')->assertOk()->assertJsonStructure(['items','total','next_cursor'])->json();
            $this->assertLessThanOrEqual(2,count($list['items']),$role.' '.$type);
            if(!in_array($role,$spec['read']))$this->assertSame([],$list['items'],$role.' cannot read '.$type);
            $page=$this->get('/panel/'.$type);
            if(in_array($role,array_unique(array_merge($spec['read'],$spec['write']))))$page->assertOk()->assertSee('portal-boot');else $page->assertForbidden();
            $before=Records::query($type)->count();
            $create=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/portal-api/v1/resources/'.$type,[]);
            $this->assertContains($create->status(),[400,403,404,409,422],$role.' '.$type.' invalid create: '.$create->getContent());
            $this->assertSame($before,Records::query($type)->count(),$type.' invalid create must not write');
        }
    }
}
