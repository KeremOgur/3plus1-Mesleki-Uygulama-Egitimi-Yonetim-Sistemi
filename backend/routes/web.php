<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{PortalAuthController,PortalController};

Route::get('/',fn()=>redirect(auth()->check()?'/panel':'/giris'));
Route::get('/giris',[PortalAuthController::class,'loginForm'])->name('login');
Route::post('/giris',[PortalAuthController::class,'login'])->middleware('throttle:10,1');
Route::get('/parolami-unuttum',[PortalAuthController::class,'forgotForm']);
Route::post('/parolami-unuttum',[PortalAuthController::class,'forgot'])->middleware('throttle:3,1');
Route::get('/parola-yenile/{token}',[PortalAuthController::class,'resetForm'])->name('password.reset');
Route::post('/parola-yenile',[PortalAuthController::class,'reset'])->middleware('throttle:5,1');
Route::middleware(['auth','auth.session'])->group(function(){
    Route::get('/gorev',[PortalAuthController::class,'selection'])->name('portal.assignment');
    Route::post('/gorev',[PortalAuthController::class,'select']);
    Route::post('/cikis',[PortalAuthController::class,'logout']);
    Route::middleware([\App\Http\Middleware\PortalContext::class])->group(function(){
        Route::get('/panel',[PortalController::class,'page']);
        Route::get('/panel/{page}/{id?}',[PortalController::class,'page']);
        Route::prefix('portal-api/v1')->middleware([\App\Http\Middleware\RequestContext::class])->group(function(){
            // Reuse the registered API actions and constraints; the web middleware adds CSRF/session protection.
            $sourceRoutes=array_values(iterator_to_array(Route::getRoutes()));
            foreach($sourceRoutes as $source) {
                if(!str_starts_with($source->uri(),'api/v1/') || str_starts_with($source->uri(),'api/v1/auth/'))continue;
                $copy=Route::match(array_diff($source->methods(),['HEAD']),substr($source->uri(),7),$source->getAction('uses'));
                $copy->where($source->wheres);
                if(in_array('idempotent',$source->gatherMiddleware()))$copy->middleware('idempotent');
            }
            Route::get('reference-users',[PortalController::class,'users']);
            Route::get('overview',[PortalController::class,'overview']);
            Route::get('interview-candidates',[PortalController::class,'interviewCandidates']);
            Route::get('applications/{id}/preference-policy',[PortalController::class,'preferencePolicy']);
            Route::get('placements/{id}/student-summary',[PortalController::class,'studentSummary']);
            Route::post('notifications/{id}/read',[PortalController::class,'markRead'])->middleware('idempotent');
        });
    });
});
