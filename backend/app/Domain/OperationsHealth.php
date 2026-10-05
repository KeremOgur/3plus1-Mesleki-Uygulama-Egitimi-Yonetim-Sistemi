<?php
namespace App\Domain;
use Illuminate\Support\Facades\{DB,Log};
class OperationsHealth
{
 public function check(): array {
  $result=['failed_jobs'=>DB::table('failed_jobs')->count(),'queue_backlog'=>DB::table('jobs')->count(),'quarantined_documents'=>Records::query('documents')->where('scan_status','!=','temiz')->count(),'backup_enabled'=>(bool)config('backup.enabled')];$alarms=[];
  if($result['failed_jobs']>0)$alarms[]='Başarısız kuyruk işi var.';if($result['queue_backlog']>100)$alarms[]='Kuyrukta yüzün üzerinde bekleyen iş var.';
  $free=disk_free_space(storage_path());$result['free_storage_bytes']=$free;if($free===false || $free<1024*1024*1024)$alarms[]='Özel depolama boş alanı bir GB altında veya ölçülemiyor.';
  if(config('backup.enabled')){$last=0;foreach(glob(rtrim(config('backup.directory'),'/\\').'/*/manifest.json')?:[] as $f)$last=max($last,filemtime($f));$result['last_backup_at']=$last?gmdate('c',$last):null;if(!$last || $last<now()->subDay()->timestamp)$alarms[]='Son yirmi dört saatte tamamlanmış yedek bulunamadı.';}
  $result['alarms']=$alarms;if($alarms)Log::warning('MUE işletim alarmı',$result);return $result;
 }
}
