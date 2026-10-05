<?php
namespace App\Jobs;
use App\Domain\Records;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB,Storage};
use Symfony\Component\Process\Process;
class ScanDocument implements ShouldQueue
{
    use Queueable;
    public int $tries=3;
    public int $timeout=70;
    public function __construct(public string $documentId){}
    public function handle(): void
    {
        $doc=Records::get('documents',$this->documentId);
        if(!$scanner=config('mue.scanner')) throw new \RuntimeException('Zararlı içerik tarayıcısı yapılandırılmadı; belge karantinada.');
        $process=new Process([$scanner,'--no-summary',Storage::disk('local')->path($doc->object_key)]); $process->setTimeout(60); $process->run();
        $code=$process->getExitCode(); if(!in_array($code,[0,1])) throw new \RuntimeException('Belge taraması tamamlanamadı.');
        DB::transaction(function()use($doc,$code){Records::auditContext($doc->created_by,null,'Belge zararlı içerik taraması');$doc->fill(['scan_status'=>$code===0?'temiz':'zararli','state'=>$code===0?'hazir':'karantina'])->save();});
    }
}
