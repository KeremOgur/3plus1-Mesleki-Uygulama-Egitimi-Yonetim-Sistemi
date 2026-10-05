<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
/** Separate committed connections exercise PostgreSQL locks, unlike transactional unit fixtures. */
class ConcurrencyTest extends TestCase
{
    private function race(array $first,array $second): array
    {
        $c=DB::connection()->getConfig();$this->assertSame('mue_test',$c['database']);
        $env=['APP_ENV'=>'testing','DB_URL'=>'','DB_DATABASE'=>$c['database'],'DB_CONNECTION'=>'pgsql','DB_HOST'=>$c['host'],'DB_PORT'=>(string)$c['port'],'DB_USERNAME'=>$c['username'],'DB_PASSWORD'=>$c['password'],'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync'];
        $workers=[];foreach([$first,$second] as $payload){$p=new Process([PHP_BINARY,'-c',php_ini_loaded_file(),base_path('tests/Support/placement_worker.php')],base_path(),$env,json_encode($payload),30);$p->start();$workers[]=$p;}
        $results=[];foreach($workers as $p){$p->wait();$this->assertSame(0,$p->getExitCode(),$p->getErrorOutput());$results[]=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}
        $this->assertCount(1,array_filter($results,fn($r)=>$r['success']));$failed=array_values(array_filter($results,fn($r)=>!$r['success']))[0];$this->assertContains($failed['sqlstate'],['P0001','23505','23P01']);return $results;
    }
    public function test_parallel_capacity_does_not_overflow(): void
    {
        $g=F::graph();$student=F::make('students',Records::scope($g['student'])+['user_id'=>User::factory()->create()->id,'student_no'=>'CONCURRENT-'.bin2hex(random_bytes(5))]);
        $app=F::make('applications',['student_id'=>$student->id]+Records::scope($g['app']));
        $payload=Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'onaylandi','credited_minutes'=>0,'credited_outcomes'=>[]];
        $other=array_replace($payload,['student_id'=>$student->id,'application_id'=>$app->id]);$this->race($payload,$other);
        $this->assertSame(1,Records::query('placements')->where('offer_id',$g['offer']->id)->count());
    }
    public function test_parallel_duplicate_student_is_rejected(): void
    {
        $g=F::graph();$g['offer']->capacity=2;$g['offer']->save();
        $payload=Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on,'state'=>'onaylandi','credited_minutes'=>0,'credited_outcomes'=>[]];$this->race($payload,$payload);
        $this->assertSame(1,Records::query('placements')->where('student_id',$g['student']->id)->where('term_id',$g['term']->id)->count());
    }
}
