<?php
namespace App\Jobs;
use App\Domain\{Records,Matching,DomainError};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
class RunMatching implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public int $timeout=150;
    public function __construct(public string $runId) {}
    public function handle(Matching $matching): void
    {
        $run=DB::transaction(function() {
            $r=Records::query('matching_runs')->lockForUpdate()->findOrFail($this->runId);
            if ($r->state!=='kuyrukta') return null;
            Records::auditContext($r->created_by,null,'Eşleştirme kuyruğu');
            $r->state='calisiyor'; $r->save(); return $r->refresh();
        });
        if (!$run) return;
        try {
            if ($matching->hash($run->snapshot)!==$run->snapshot_hash) throw new DomainError('RUN_INVALID','Anlık görüntü özeti geçersiz.');
            $process=new Process([config('mue.python'),config('mue.optimizer_runner')]);
            $process->setInput(json_encode($run->snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $process->setTimeout(135); $process->mustRun();
            $result=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR); $matching->validate($run->snapshot,$result);
            DB::transaction(function() use($run,$result) {
                Records::auditContext($run->created_by,null,'Bağımsız kısıt doğrulamasından geçen öneri');
                foreach ($run->snapshot['candidates'] as $p) Records::create('candidate_scores',array_merge(Records::scope(Records::get('applications',$p['student_id'])),['run_id'=>$run->id,'company_id'=>Records::get('offers',$p['offer_id'])->company_id,'application_id'=>$p['student_id'],'offer_id'=>$p['offer_id'],'eligible'=>$p['eligible'],'reasons'=>$p['reasons'],'rank'=>$p['rank'],'preference_score'=>$p['preference_score'],'transport_score'=>$p['transport_score'],'score'=>$p['score']]));
                foreach ($result['assignments'] as $p) Records::create('match_results',array_merge(Records::scope(Records::get('applications',$p['student_id'])),['run_id'=>$run->id,'application_id'=>$p['student_id'],'offer_id'=>$p['offer_id'],'explanation'=>$p['explanation']]));
                foreach ($result['unplaced'] as $p) Records::create('match_results',array_merge(Records::scope(Records::get('applications',$p['student_id'])),['run_id'=>$run->id,'application_id'=>$p['student_id'],'offer_id'=>null,'explanation'=>['reasons'=>$p['reasons']]]));
                $run->fill(['state'=>'tamamlandi','solver_status'=>$result['solver_status'],'objective_values'=>$result['objective_values'],'result'=>$result])->save();
            });
        } catch (\Throwable $e) {
            DB::transaction(function() use($run,$e) {
                Records::auditContext($run->created_by,null,'Eşleştirme başarısız');
                $run->fill(['state'=>'basarisiz','failure_message'=>'Öneri üretilemedi; teknik kayıtları inceleyin.'])->save();
            });
            report($e);
        }
    }
    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function() { $run=Records::get('matching_runs',$this->runId); if(in_array($run->state,['kuyrukta','calisiyor'])) { Records::auditContext($run->created_by,null,'Kuyruk işi sonlandırıldı'); $run->fill(['state'=>'basarisiz','failure_message'=>'Kuyruk işi tamamlanamadı.'])->save(); } });
    }
}
