<?php
require __DIR__.'/../backend/vendor/autoload.php';
$app=require __DIR__.'/../backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents(__DIR__.'/../docs/resource-manifest.json',json_encode(config('domain'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
\Illuminate\Support\Facades\Artisan::call('route:list',['--json'=>true]);
file_put_contents(__DIR__.'/../docs/routes.json',\Illuminate\Support\Facades\Artisan::output());
echo 'Alan ve yol sözleşmesi güncellendi.'.PHP_EOL;
