<?php
require __DIR__.'/../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local','testing') || config('database.connections.pgsql.database')!=='mue')throw new RuntimeException('Only the local synthetic demo database is allowed.');
use App\Domain\Records;
use Tests\Support\Fixture as F;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Storage};
$institution=Records::query('institutions')->where('code','DEMO-MUE')->sole();
$program=Records::query('programs')->where('institution_id',$institution->id)->firstOrFail();
DB::transaction(function()use($institution,$program){
    $email='qa.foreign@demo.mue.invalid';$u=User::firstOrCreate(['email'=>$email],['name'=>'QA — sentetik kapsam dışı öğrenci','password'=>\Illuminate\Support\Str::random(40),'active'=>true]);$u->external_subject='mue-demo:qa-foreign';$u->save();
    $student=Records::query('students')->where('user_id',$u->id)->first()??F::make('students',['institution_id'=>$institution->id,'program_id'=>$program->id,'user_id'=>$u->id,'student_no'=>'QA-FOREIGN-20261007','gpa'=>2.5,'gpa_scale'=>4]);
    $company=Records::query('companies')->where('tax_no','QA-FOREIGN-20261007')->first()??F::make('companies',['institution_id'=>$institution->id,'legal_name'=>'QA — kapsam dışı sentetik işletme','tax_no'=>'QA-FOREIGN-20261007','organization_type'=>'ozel','state'=>'aktif']);
    $key='karantina/qa-foreign-20261007.txt';Storage::disk('local')->put($key,'Synthetic QA evidence; no personal data.');
    $doc=Records::query('documents')->where('object_key',$key)->first()??F::make('documents',Records::scope($student)+['target_type'=>'students','target_id'=>$student->id,'object_key'=>$key,'classification'=>'gizli','scan_status'=>'bekliyor','sha256'=>hash('sha256',Storage::disk('local')->get($key)),'retention_start_event'=>'synthetic-qa','retention_until'=>now()->addYears(5)->toDateString(),'legal_hold'=>false]);
    file_put_contents(storage_path('logs/full-qa-20261007/foreign-fixture.json'),json_encode(['students'=>$student->id,'companies'=>$company->id,'documents'=>$doc->id]));
    $password='Qa.Mfa.Synthetic!987';$mfa=User::firstOrCreate(['email'=>'qa.mfa@demo.mue.invalid'],['name'=>'QA — sentetik MFA','password'=>$password,'active'=>true]);$mfa->forceFill(['external_subject'=>'mue-demo:qa-mfa','mfa_secret'=>'JBSWY3DPEHPK3PXP','mfa_confirmed_at'=>now(),'mfa_last_counter'=>null])->save();
    if(!Records::query('role_assignments')->where('user_id',$mfa->id)->exists())F::make('role_assignments',['institution_id'=>$institution->id,'user_id'=>$mfa->id,'role'=>'sistem_yoneticisi','valid_from'=>now()->subDay(),'state'=>'aktif']);
    Storage::disk('local')->put('qa-mfa-credentials.json',json_encode(['email'=>$mfa->email,'password'=>$password,'secret'=>'JBSWY3DPEHPK3PXP']));
});
echo "Local synthetic IDOR/MFA fixtures prepared; credentials remain private.\n";
