<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController,ResourceController,ProcessController,DocumentController,ImportController,ReportController,AccountController,AdministrativeController};

Route::prefix('v1')->group(function(){
    Route::post('auth/login',[AuthController::class,'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password',[AuthController::class,'forgot'])->middleware('throttle:3,1');
    Route::post('auth/reset-password',[AuthController::class,'reset'])->middleware('throttle:5,1');
    Route::middleware('auth:sanctum')->group(function(){
        Route::get('auth/me',[AuthController::class,'me']); Route::post('auth/logout',[AuthController::class,'logout']);
        Route::get('schema',fn()=>['resources'=>array_map(fn($s)=>array_diff_key($s,['write'=>1,'read'=>1]),config('domain')),'portals'=>['ogrenci','akademik_danisman','isletme','myo'],'timezone'=>'Europe/Istanbul']);
        Route::get('forms',fn()=>config('forms'));
        Route::get('reports/{report}',[ReportController::class,'report']);
        Route::get('exports/{resource}',[ReportController::class,'export']);
        Route::get('documents/{id}/download',[DocumentController::class,'download']);
        Route::get('interviews/{id}/student-summary',[AdministrativeController::class,'interviewSummary']);
        Route::get('applications/{id}/semester-guidance',[AdministrativeController::class,'semesterGuidance']);
        Route::get('placements/{id}/education/{action}',[ProcessController::class,'education'])->where('action','devam|denetim|baslama');
        Route::get('resources/{resource}',[ResourceController::class,'index']);
        Route::get('resources/{resource}/{id}',[ResourceController::class,'show']);
        Route::get('resources/{resource}/{id}/history',[ResourceController::class,'history']);
        Route::middleware('idempotent')->group(function(){
            Route::post('accounts',[AccountController::class,'createAccount']);
            Route::post('accounts/{id}/deactivate',[AccountController::class,'deactivate']);
            Route::put('accounts/{id}',[AccountController::class,'updateAccount']);
            Route::post('assignments/{id}/revoke',[AdministrativeController::class,'revoke']);
            Route::post('protocols/{id}/renew',[AdministrativeController::class,'renewProtocol']);
            Route::post('change-requests/{id}/credits',[AdministrativeController::class,'changeCredits']);
            Route::post('placements/{id}/online-permission',[AdministrativeController::class,'onlinePermission']);
            Route::post('placements/{id}/online-proposal',[AdministrativeController::class,'onlineProposal']);
            Route::post('board-decisions/{resource}/{id}',[AdministrativeController::class,'boardDecision']);
            Route::post('directorate-approvals/{resource}/{id}',[AdministrativeController::class,'directorateApproval']);
            Route::post('documents',[DocumentController::class,'upload']);
            Route::post('imports/preview',[ImportController::class,'preview']);
            Route::post('imports/{id}/approve',[ImportController::class,'approve']);
            Route::put('applications/{id}/preferences',[ProcessController::class,'preferences']);
            Route::post('applications/{id}/correct-preferences',[ProcessController::class,'correctPreferences']);
            Route::post('students/{id}/confirm-profile',[AdministrativeController::class,'confirmProfile']);
            Route::post('applications/{id}/submit-preferences',[ProcessController::class,'submitPreferences']);
            Route::post('applications/{id}/verify-transport',[ProcessController::class,'verifyTransport']);
            Route::post('matching-runs',[ProcessController::class,'run']);
            Route::post('matching-runs/{id}/recommendations',[ProcessController::class,'recommendations']);
            Route::post('placements/{id}/review',[ProcessController::class,'review']);
            Route::post('placements/{id}/calculate-success',fn(\Illuminate\Http\Request $r,string $id)=>app(ProcessController::class)->education($r,$id,'basari'));
            Route::post('publications',[ProcessController::class,'publish']);
            Route::post('appeals',[ProcessController::class,'appeal']);
            Route::post('resources/{resource}',[ResourceController::class,'store']);
            Route::put('resources/{resource}/{id}',[ResourceController::class,'update']);
            Route::post('resources/{resource}/{id}/transition',[ResourceController::class,'transition']);
        });
    });
});
