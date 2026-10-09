"""Run the existing lifecycle assertions against a real local HTTP server.

The generated test intentionally uses an isolated mue_qa_lifecycle_* database,
committed synthetic fixtures, a real database queue worker and retrospective
15-week dates ending today. Official/scanner document fixtures stay synthetic.
"""
from pathlib import Path
import hashlib

root=Path(__file__).resolve().parents[2]
source=(root/'backend/tests/Feature/CompleteLifecycleTest.php').read_text(encoding='utf-8')
source=source.replace('class CompleteLifecycleTest extends TestCase','class HttpLifecycleQaTest extends TestCase')
source=source.replace(' use DatabaseTransactions;','')
source=source.replace(' private array $g,$actors=[];private string $doc;', ''' private array $g,$actors=[],$httpTokens=[];private string $doc;
 protected function setUp(): void { parent::setUp(); $this->assertStringStartsWith('mue_qa_lifecycle_',config('database.connections.pgsql.database')); }
 private function realHttp(string $path,array $data,array $headers=[],string $method='POST'): array {
  $ch=curl_init('http://127.0.0.1:8092/api/v1/'.$path);$headers[]='Accept: application/json';$headers[]='Content-Type: application/json';
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_TIMEOUT=>40]);
  $start=microtime(true);$body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  file_put_contents(storage_path('logs/full-qa-20261007/lifecycle-http.jsonl'),json_encode(['path'=>$path,'method'=>$method,'status'=>$status,'seconds'=>microtime(true)-$start]).PHP_EOL,FILE_APPEND);
  if($body===false)throw new \\RuntimeException($error);return [$status,$body];
 }
''')
start=source.index(' private function send(')
end=source.index(' private function input(',start)
source=source[:start]+''' private function send(string $path,array $data=[],string $method='POST'){
  $role=$this->actorsRole();$u=$this->actors[$role][0];$a=$this->actors[$role][1];
  if(!isset($this->httpTokens[$role])){$password='Qa.Http.Local!987';$u->password=$password;$u->save();[$status,$body]=$this->realHttp('auth/login',['email'=>$u->email,'password'=>$password]);$this->assertSame(200,$status,$body);$this->httpTokens[$role]=json_decode($body,true,512,JSON_THROW_ON_ERROR)['token'];}
  [$status,$body]=$this->realHttp($path,$data,['Authorization: Bearer '.$this->httpTokens[$role],'X-Assignment-Id: '.$a->id,'Idempotency-Key: '.Str::uuid()],$method);
  return \\Illuminate\\Testing\\TestResponse::fromBaseResponse(new \\Illuminate\\Http\\Response($body,$status,['Content-Type'=>'application/json']));
 }
'''+source[end:]
source=source.replace("['code'=>'AUDIT-'.Str::random(8)]", "['code'=>'HTTP-QA-'.Str::random(8),'starts_on'=>today()->subDays(104)->toDateString(),'ends_on'=>today()->toDateString()]")
source=source.replace("'prepared_on'=>'2026-10-05'", "'prepared_on'=>$p->starts_on")
source=source.replace("(new \\App\\Jobs\\RunMatching($runId))->handle(app(Matching::class));$run=Records::get('matching_runs',$runId);", "$deadline=microtime(true)+25;do{usleep(100000);$run=Records::get('matching_runs',$runId);}while(in_array($run->state,['kuyrukta','calisiyor']) && microtime(true)<$deadline);")
out=root/'backend/storage/logs/full-qa-20261007/HttpLifecycleQaTest.php'
out.write_text(source,encoding='utf-8')
print('Generated isolated real-HTTP lifecycle; source SHA256 '+hashlib.sha256((root/'backend/tests/Feature/CompleteLifecycleTest.php').read_bytes()).hexdigest())
