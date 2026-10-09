<?php
// Disposable, isolated local database only. Never operates on the demo or production DB.
require __DIR__.'/../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Domain\Records;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
if(!str_starts_with(config('database.connections.pgsql.database'),'mue_qa_lifecycle_'))throw new RuntimeException('Isolated QA database required.');
$out=__DIR__.'/../../backend/storage/logs/full-qa-20261007/queue-outage.json';
if(in_array('--verify',$argv)) {
    $data=json_decode(file_get_contents($out),true);$r=Records::get('matching_runs',$data['run_id']);
    $data['after_worker']=$r->state;$data['remaining_jobs']=DB::table('jobs')->count();
    if($r->state!=='tamamlandi' || $data['remaining_jobs']!==0)throw new RuntimeException('Queued run did not finish.');
    file_put_contents($out,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo 'Queue recovery passed'.PHP_EOL;exit;
}
$template=Records::query('matching_policies')->where('state','onaylandi')->orderByDesc('created_at')->firstOrFail();
$term=Records::get('academic_terms',$template->term_id);
$newTerm=Records::create('academic_terms',array_replace($term->only(array_keys(config('domain.academic_terms.fields'))),['institution_id'=>$template->institution_id,'code'=>'QUEUE-QA-'.Str::random(8),'preference_deadline'=>now()->subMinute(),'state'=>'acik']));
$policy=Records::create('matching_policies',array_replace(Records::scope($template),$template->only(array_keys(config('domain.matching_policies.fields'))),['term_id'=>$newTerm->id,'state'=>'onaylandi']));
$a=Records::query('role_assignments')->where('institution_id',$policy->institution_id)->where('role','bolum_komisyon')->where('state','aktif')->firstOrFail();
$u=User::findOrFail($a->user_id);
$request=function(string $path,array $body,array $headers=[]){$ch=curl_init('http://127.0.0.1:8092/api/v1/'.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','Content-Type: application/json'],$headers),CURLOPT_TIMEOUT=>40]);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$status,json_decode($raw,true)];};
[$status,$auth]=$request('auth/login',['email'=>$u->email,'password'=>'Qa.Http.Local!987']);if($status!==200)throw new RuntimeException('QA login failed.');
[$status,$data]=$request('matching-runs',['policy_id'=>$policy->id],['Authorization: Bearer '.$auth['token'],'X-Assignment-Id: '.$a->id,'Idempotency-Key: '.Str::uuid()]);
if($status!==202 || ($data['state']??null)!=='kuyrukta')throw new RuntimeException('Worker-off submission did not return 202.');
$data+=['http_status'=>$status,'pending_jobs'=>DB::table('jobs')->count(),'worker_off_state'=>Records::get('matching_runs',$data['run_id'])->state,'fixture'=>'Isolated empty candidate set; tests asynchronous dispatch and recovery only.'];
if($data['pending_jobs']!==1 || $data['worker_off_state']!=='kuyrukta')throw new RuntimeException('Expected one persistent pending job.');
file_put_contents($out,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo 'Worker-off submission passed'.PHP_EOL;
