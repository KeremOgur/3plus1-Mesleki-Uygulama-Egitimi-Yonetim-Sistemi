<?php
require __DIR__.'/../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;
if(!app()->environment('local') || config('database.connections.pgsql.database')!=='mue')throw new RuntimeException('Local demo DB required.');
$institution=Records::query('institutions')->where('code','DEMO-MUE')->firstOrFail();
$ids=Records::query('documents')->where('institution_id',$institution->id)->where('original_name','qa-http.png')->where('retention_start_event','synthetic-qa')->pluck('id')->all();
$failures=DB::table('failed_jobs')->get()->filter(function($row)use($ids){$payload=json_decode($row->payload,true);if(($payload['displayName']??null)!=='App\\Jobs\\ScanDocument')return false;foreach($ids as $id)if(str_contains($payload['data']['command']??'',$id))return true;return false;});
$out=__DIR__.'/../../backend/storage/logs/full-qa-20261007/';
if($failures->count() || !file_exists($out.'expected-scanner-failures.json'))file_put_contents($out.'expected-scanner-failures.json',json_encode($failures->values(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
if($failures->count())DB::table('failed_jobs')->whereIn('id',$failures->pluck('id'))->delete();
$user=User::where('email','qa.mfa@demo.mue.invalid')->where('external_subject','mue-demo:qa-mfa')->first();
if($user){$user->active=false;$user->save();$user->tokens()->delete();Records::auditContext(null,null,'Sentetik QA MFA görevinin test sonunda kapatılması');foreach(Records::query('role_assignments')->where('user_id',$user->id)->where('institution_id',$institution->id)->where('state','aktif')->get() as $assignment){$assignment->state='pasif';$assignment->valid_until=now();$assignment->save();}}
$result=['archived_expected_scanner_failures'=>$failures->count(),'qa_mfa_deactivated'=>(bool)$user,'quarantined_qa_documents_preserved'=>count($ids),'remaining_failed_jobs'=>DB::table('failed_jobs')->count()];
file_put_contents($out.'cleanup.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;
