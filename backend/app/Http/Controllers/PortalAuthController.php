<?php
namespace App\Http\Controllers;
use App\Domain\{Records,DomainError};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class PortalAuthController extends Controller
{
    public function loginForm(){return auth()->check()?redirect('/panel'):view('portal.auth',['mode'=>'login']);}
    public function login(Request $r)
    {
        try {$user=app(AuthController::class)->authenticate($r);}
        catch(DomainError $e){return back()->withInput($r->only('email'))->withErrors(['email'=>$e->getMessage()]);}
        Auth::login($user);$r->session()->regenerate();
        $r->session()->put('mfa_verified',(bool)$user->mfa_confirmed_at);
        $roles=$this->assignments($r);
        if($roles->count()===1)$r->session()->put('assignment_id',$roles->first()->id);
        return redirect($roles->count()===1?'/panel':'/gorev');
    }
    public function assignments(Request $r){return Records::query('role_assignments')->where('user_id',$r->user()->id)->where('state','aktif')->where('valid_from','<=',now())->where(fn($q)=>$q->whereNull('valid_until')->orWhere('valid_until','>=',now()))->get();}
    public function selection(Request $r){return view('portal.assignment',['assignments'=>$this->assignments($r)]);}
    public function select(Request $r)
    {
        $r->validate(['assignment_id'=>'required|uuid']);$a=$this->assignments($r)->firstWhere('id',$r->assignment_id);abort_unless($a,403);
        $r->session()->put('assignment_id',$a->id);$r->session()->regenerate();return redirect('/panel');
    }
    public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/giris')->with('success','Oturumunuz kapatıldı.');}
    public function forgotForm(){return view('portal.auth',['mode'=>'forgot']);}
    public function resetForm(Request $r,string $token){return view('portal.auth',['mode'=>'reset','token'=>$token,'email'=>$r->query('email')]);}
    public function forgot(Request $r){try{$result=app(AuthController::class)->forgot($r);return back()->with('success',$result['message']);}catch(DomainError $e){return back()->withErrors(['email'=>$e->getMessage()]);}}
    public function reset(Request $r){try{app(AuthController::class)->reset($r);return redirect('/giris')->with('success','Parolanız yenilendi; giriş yapabilirsiniz.');}catch(DomainError $e){return back()->withErrors(['email'=>$e->getMessage()]);}}
}
