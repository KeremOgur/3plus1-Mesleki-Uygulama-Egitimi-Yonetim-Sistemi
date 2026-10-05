<?php
namespace App\Http\Middleware;
use App\Domain\{ScopeAccess,DomainError};
use Illuminate\Http\Request;
class PortalContext
{
    public function handle(Request $request,\Closure $next)
    {
        if(!$request->user()?->active || !$request->user()->allowedEnvironment()) { auth()->logout();$request->session()->invalidate();if(!$request->expectsJson())return redirect('/giris')->withErrors(['email'=>'Hesabınız bu ortamda aktif değil.']);throw new DomainError('HESAP_PASIF','Hesabınız aktif değil.',401); }
        $id=$request->session()->get('assignment_id');
        if(!$id) return $request->expectsJson()?response()->json(['message'=>'Devam etmek için aktif görevinizi seçin.'],403):redirect()->route('portal.assignment');
        $request->headers->set('X-Assignment-Id',$id);
        try {$assignment=app(ScopeAccess::class)->assignment($request->user());}
        catch(DomainError $e) {$request->session()->forget('assignment_id');if(!$request->expectsJson())return redirect()->route('portal.assignment')->with('error','Göreviniz sona erdi veya kaldırıldı.');throw $e;}
        $request->attributes->set('portal_assignment',$assignment);
        $response=$next($request);$response->headers->set('Cache-Control','no-store');$response->headers->set('X-Content-Type-Options','nosniff');return $response;
    }
}
