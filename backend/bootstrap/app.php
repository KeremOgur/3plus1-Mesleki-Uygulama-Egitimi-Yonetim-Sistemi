<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['idempotent'=>\App\Http\Middleware\Idempotent::class]);
        $middleware->api(prepend:[\App\Http\Middleware\RequestContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn($r,$e)=>$r->is('api/*') || $r->expectsJson());
        $exceptions->report(function(\Illuminate\Database\QueryException $e){\Illuminate\Support\Facades\Log::warning('Veritabanı işlemi reddedildi.',['sqlstate'=>$e->getCode(),'request_id'=>request()->attributes->get('request_id')]);return false;});
        $exceptions->render(function (\Throwable $e,\Illuminate\Http\Request $r) {
            if(!$r->is('api/*','portal-api/*')) return null;
            $status=500; $code='SUNUCU_HATASI'; $message='İşlem tamamlanamadı; teknik ekibe başvurun.'; $fields=[];
            if($e instanceof \App\Domain\DomainError) { $status=$e->httpStatus;$code=$e->errorCode;$message=$e->getMessage();$fields=$e->fields; }
            elseif($e instanceof \Illuminate\Validation\ValidationException) { $status=422;$code='VALIDATION_ERROR';$message='Belirtilen alanları düzeltin.';$fields=$e->errors(); }
            elseif($e instanceof \Illuminate\Auth\AuthenticationException) { $status=401;$code='OTURUM_GEREKLI';$message='Bu işlem için giriş yapmalısınız.'; }
            elseif($e instanceof \Illuminate\Auth\Access\AuthorizationException) { $status=403;$code='FORBIDDEN_SCOPE';$message='Bu işlem için yetkiniz yok.'; }
            elseif($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $status=404;$code='KAYIT_BULUNAMADI';$message='Kayıt bulunamadı.'; }
            elseif($e instanceof \Illuminate\Database\QueryException && in_array($e->getCode(),['23505','23514','P0001','23503','23P01'])) { $status=409;$code='DATA_CONFLICT';$message='İşlem kayıt bütünlüğü, tarih veya kontenjan koşuluyla çakıştı.'; }
            elseif($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) { $status=$e->getStatusCode();$code='ISTEK_REDDEDILDI';$message=match($status){419=>'Oturum doğrulaması sona erdi. Sayfayı yenileyip yeniden deneyin.',429=>'Çok fazla istek gönderildi. Kısa bir süre sonra yeniden deneyin.',403=>'Bu işlem için yetkiniz yok.',default=>'İstek kabul edilemedi.'}; }
            return response()->json(['code'=>$code,'message'=>$message,'field_errors'=>$fields,'request_id'=>$r->attributes->get('request_id')],$status);
        });
    })->create();
