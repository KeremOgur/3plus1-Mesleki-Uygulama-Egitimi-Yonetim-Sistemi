<?php
namespace App\Http\Middleware;
class RequestContext
{
    public function handle(\Illuminate\Http\Request $r,\Closure $next)
    {
        $r->attributes->set('request_id',(string)\Illuminate\Support\Str::uuid());
        $id=$r->route('id');
        if($id!==null) {
            $account=$r->is('api/v1/accounts/*','portal-api/v1/accounts/*');
            $valid=$account?filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false:\Illuminate\Support\Str::isUuid($id);
            abort_unless($valid,404,'Kayıt bulunamadı.');
        }
        $response=$next($r);$response->headers->set('X-Request-Id',$r->attributes->get('request_id'));$response->headers->set('X-Content-Type-Options','nosniff');$response->headers->set('Cache-Control','no-store');return $response;
    }
}
