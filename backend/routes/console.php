<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mue:outbox',function(){ $this->info('İşlenen bildirim olayları: '.app(\App\Domain\Outbox::class)->deliver()); });
Artisan::command('mue:maintenance',function(){ \Illuminate\Support\Facades\DB::transaction(fn()=>app(\App\Domain\Maintenance::class)->run());$this->info('Takvim, belge ve eğitim uyarıları güncellendi.'); });
Artisan::command('mue:bootstrap {email} {--role=sistem_yoneticisi}',function(){
    $role=$this->option('role');if(!in_array($role,['sistem_yoneticisi','mudur'])){$this->error('İlk hesap rolü sistem_yoneticisi veya mudur olmalıdır.');return 1;}
    if(\App\Domain\Records::query('role_assignments')->where('role',$role)->exists()){$this->error('İlk görevlendirme zaten var; yetkili görev yönetimini kullanın.');return 1;}
    $password=$this->secret('En az 12 karakter, büyük/küçük harf, sayı ve simge içeren parola');
    \Illuminate\Support\Facades\Validator::make(['password'=>$password],['password'=>[\Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]])->validate();
    \Illuminate\Support\Facades\DB::transaction(function()use($role,$password){
        $institution=\App\Domain\Records::query('institutions')->where('code','FU-EOSBMYO')->first()??\App\Domain\Records::create('institutions',['code'=>'FU-EOSBMYO','name'=>'Fırat Üniversitesi Elazığ Organize Sanayi Bölgesi Meslek Yüksekokulu']);
        $u=\App\Models\User::create(['name'=>$role==='mudur'?'MYO Müdürü':'Teknik Yönetici','email'=>$this->argument('email'),'password'=>$password,'active'=>true]);
        \App\Domain\Records::auditContext($u->id,null,'Yerel kurulumda açık ilk görev oluşturma');
        \App\Domain\Records::create('role_assignments',['institution_id'=>$institution->id,'user_id'=>$u->id,'role'=>$role,'valid_from'=>now(),'state'=>'aktif']);
    });$this->info('İlk hesap oluşturuldu. Teknik yöneticiye akademik karar yetkisi verilmedi.');
});
Schedule::command('mue:outbox')->everyMinute()->withoutOverlapping();
Artisan::command('mue:demo',function(){
    if(!app()->environment('local','testing')){$this->error('Demo yalnız geliştirme/test ortamında kullanılabilir.');return 1;}
    $this->call('db:seed',['--class'=>\Database\Seeders\PortalDemoSeeder::class]);
});
Schedule::command('mue:maintenance')->hourly()->withoutOverlapping();
Artisan::command('mue:backup',function(){try{$r=app(\App\Domain\Backup::class)->create();$this->info('Veritabanı ve özel belge yedeği tamamlandı: '.$r['directory']);}catch(\Throwable $e){$this->error($e instanceof \App\Domain\DomainError?$e->getMessage():'Yedekleme tamamlanamadı.');return 1;}});
Schedule::command('mue:backup')->dailyAt('02:00')->withoutOverlapping()->when(fn()=>config('backup.enabled'));
Artisan::command('mue:health',function(){$r=app(\App\Domain\OperationsHealth::class)->check();$this->line(json_encode($r,JSON_UNESCAPED_UNICODE));return $r['alarms']?1:0;});
Schedule::command('mue:health')->hourly()->withoutOverlapping();
Artisan::command('mue:mfa-enroll {email}',function(){
    $u=\App\Models\User::where('email',$this->argument('email'))->firstOrFail();if($u->mfa_confirmed_at){$this->error('Bu hesabın doğrulayıcısı zaten kurulmuş; kimlik doğrulanmadan sıfırlanmaz.');return 1;}
    $mfa=app(\App\Domain\Mfa::class);$secret=$mfa->secret();$this->warn('Anahtarı yalnız hesap sahibiyle güvenli kurulum sırasında paylaşın; günlük veya Git deposuna kaydetmeyin.');$this->line('Doğrulayıcı anahtarı: '.$secret);
    $counter=$mfa->counter($secret,$this->secret('Doğrulayıcı uygulamadaki altı haneli kod'));if($counter===null){$this->error('Kod doğrulanamadı; kurulum saklanmadı.');return 1;}
    $u->mfa_secret=$secret;$u->mfa_last_counter=$counter;$u->mfa_confirmed_at=now();$u->save();$u->tokens()->delete();$this->info('Çok faktörlü giriş etkinleştirildi; eski API anahtarları iptal edildi.');
});
