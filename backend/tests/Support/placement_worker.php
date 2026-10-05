<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing')||config('database.connections.pgsql.database')!=='mue_test'){fwrite(STDERR,'Test veritabanı gerekir.');exit(2);}
$data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    \Illuminate\Support\Facades\DB::transaction(function()use($data){\App\Domain\Records::create('placements',$data);\Illuminate\Support\Facades\DB::select('SELECT pg_sleep(0.3)');});
    echo json_encode(['success'=>true]);
}catch(\Illuminate\Database\QueryException $e){echo json_encode(['success'=>false,'sqlstate'=>$e->getCode()]);}
