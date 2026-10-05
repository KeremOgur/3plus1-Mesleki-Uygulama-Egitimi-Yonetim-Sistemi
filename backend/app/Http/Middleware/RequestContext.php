<?php
namespace App\Http\Middleware;
class RequestContext
{
    public function handle(\Illuminate\Http\Request $r,\Closure $next)
    {
        $r->attributes->set('request_id',(string)\Illuminate\Support\Str::uuid());
        $response=$next($r);$response->headers->set('X-Request-Id',$r->attributes->get('request_id'));$response->headers->set('X-Content-Type-Options','nosniff');$response->headers->set('Cache-Control','no-store');return $response;
    }
}
