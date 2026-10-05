<?php
namespace App\Http\Controllers;
use App\Domain\{Records,DomainError};
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash,Password,RateLimiter};
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $u=$this->authenticate($request);
        $token=$u->createToken('MUE portal',$u->mfa_confirmed_at?['mue','mfa_verified']:['mue'],now()->addMinutes(config('mue.token_minutes')));
        return ['message'=>'Giriş başarılı.','token'=>$token->plainTextToken,'expires_at'=>now()->addMinutes(config('mue.token_minutes'))->toISOString(),'user'=>['id'=>$u->id,'name'=>$u->name,'email'=>$u->email],'assignments'=>$this->assignments($u)];
    }
    public function authenticate(Request $request): User
    {
        $d=$request->validate(['email'=>'required|email','password'=>'required|string','otp'=>'nullable|string|max:6']);
        $key=hash('sha256',strtolower($d['email']).'|'.$request->ip());
        if(RateLimiter::tooManyAttempts($key,5)) throw new DomainError('GIRIS_SINIRI','Çok fazla giriş denemesi; daha sonra tekrar deneyin.',429);
        $u=User::where('email',$d['email'])->first();
        if($u && !$u->allowedEnvironment())throw new DomainError('GIRIS_BASARISIZ','E-posta veya parola hatalı.',401);
        if(!$u || !$u->active || !Hash::check($d['password'],$u->password)) { RateLimiter::hit($key,300); throw new DomainError('GIRIS_BASARISIZ','E-posta veya parola hatalı.',401); }
        try{app(\App\Domain\Mfa::class)->verify($u,$d['otp']??null);}catch(DomainError $e){RateLimiter::hit($key,300);throw $e;}
        RateLimiter::clear($key);
        return $u;
    }
    public function me(Request $request) { if(!$request->user()->active) throw new DomainError('HESAP_PASIF','Hesap pasif.',401); return ['user'=>$request->user()->only('id','name','email'),'assignments'=>$this->assignments($request->user())]; }
    public function logout(Request $r) { $r->user()->currentAccessToken()?->delete(); return ['message'=>'Oturum kapatıldı.']; }
    private function assignments(User $u) { return Records::query('role_assignments')->where('user_id',$u->id)->where('state','aktif')->where('valid_from','<=',now())->where(fn($q)=>$q->whereNull('valid_until')->orWhere('valid_until','>=',now()))->get(); }
    public function forgot(Request $request)
    {
        $request->validate(['email'=>'required|email']);
        if(config('mail.default')!=='smtp') throw new DomainError('EPOSTA_YAPILANDIRILMADI','Parola yenileme için kurumsal e-posta servisi yapılandırılmalıdır.',503);
        Password::sendResetLink($request->only('email')); return ['message'=>'Hesap uygunsa parola yenileme iletisi gönderilecektir.'];
    }
    public function reset(Request $request)
    {
        $d=$request->validate(['token'=>'required','email'=>'required|email','password'=>['required','confirmed',\Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $status=Password::reset($d,function(User $u,string $password){$u->password=$password;$u->save();$u->tokens()->delete();});
        if($status!==Password::PASSWORD_RESET) throw new DomainError('PAROLA_YENILEME_BASARISIZ','Parola yenileme bağlantısı geçersiz veya süresi doldu.',422);
        return ['message'=>'Parola yenilendi.'];
    }
}
