<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\Records;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
class PerformanceTest extends TestCase
{
 use DatabaseTransactions;
 public function test_scoped_student_list_is_bounded_and_below_local_p95_target(): void {
  $g=F::graph();$tag='perf-'.bin2hex(random_bytes(6));$users=[];foreach(range(1,2000) as $i)$users[]=['name'=>'Sentetik öğrenci '.$i,'email'=>$tag.'-'.$i.'@test.example','password'=>'disabled-test-only','active'=>false];DB::table('users')->insert($users);
  $rows=[];foreach(DB::table('users')->where('email','like',$tag.'-%')->pluck('id') as $i=>$id){$row=$g['student']->getAttributes();$row['id']=(string)\Illuminate\Support\Str::uuid();$row['user_id']=$id;$row['student_no']=$tag.'-'.$i;$rows[]=$row;}
  foreach(array_chunk($rows,200) as $chunk)DB::table('students')->insert($chunk);
  $a=F::make('role_assignments',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'user_id'=>$g['u']->id,'role'=>'bolum_komisyon','valid_from'=>now()->subDay(),'state'=>'aktif']);Sanctum::actingAs($g['u']);$this->withHeader('X-Assignment-Id',$a->id);
  $times=[];foreach(range(1,20) as $i){DB::enableQueryLog();DB::flushQueryLog();$start=microtime(true);$r=$this->getJson('/api/v1/resources/students?limit=100');$times[]=microtime(true)-$start;$queries=count(DB::getQueryLog());DB::disableQueryLog();$r->assertOk()->assertJsonPath('total',2001);$this->assertCount(100,$r->json('items'));$this->assertLessThan(20,$queries,'Liste sorgu sayısı kayıt sayısıyla büyümemelidir.');}
  sort($times);$p95=$times[18];$this->assertLessThan(2,$p95);file_put_contents(storage_path('logs/final-list-benchmark.json'),json_encode(['students'=>2001,'requests'=>20,'p95_seconds'=>$p95,'mode'=>'Sequential in-process API test; not 100 concurrent production sessions']));
 }
}
