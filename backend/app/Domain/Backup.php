<?php
namespace App\Domain;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use ZipArchive;
/** Dumps before copying immutable private objects: all dump references remain available. */
class Backup
{
    public function create(): array
    {
        if(!config('backup.enabled'))throw new DomainError('YEDEK_YAPILANDIRILMADI','Otomatik yedekleme etkinleştirilmemiş.',503);
        $c=DB::connection()->getConfig();
        if($c['driver']!=='pgsql')throw new DomainError('YEDEK_VERITABANI','Yedekleme PostgreSQL bağlantısı gerektirir.',503);
        $root=config('backup.directory');
        if(!is_dir($root)&&!mkdir($root,0700,true))throw new DomainError('YEDEK_DEPOSU','Özel yedek dizini oluşturulamadı.',503);
        $privateRoot=str_replace('\\','/',realpath(config('filesystems.disks.local.root'))?:config('filesystems.disks.local.root'));
        $backupRoot=str_replace('\\','/',realpath($root));
        if(str_starts_with(strtolower($backupRoot).'/',strtolower(rtrim($privateRoot,'/')).'/'))throw new DomainError('YEDEK_DEPOSU','Yedek dizini özel belge deposunun dışında olmalıdır.',503);
        $directory=$root.DIRECTORY_SEPARATOR.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6));
        if(!mkdir($directory,0700))throw new DomainError('YEDEK_DEPOSU','Yedek dizini oluşturulamadı.',503);
        $dump=$directory.DIRECTORY_SEPARATOR.'database.dump';
        $process=new Process([config('backup.pg_dump'),'--format=custom','--no-owner','--no-acl','--file='.$dump],null,
            ['PGHOST'=>$c['host'],'PGPORT'=>(string)$c['port'],'PGDATABASE'=>$c['database'],'PGUSER'=>$c['username'],'PGPASSWORD'=>$c['password'],'PGSSLMODE'=>$c['sslmode']??'prefer'],null,config('backup.timeout_seconds'));
        try{$process->run();}catch(\Throwable){throw new DomainError('YEDEK_BASARISIZ','Veritabanı yedeği çalıştırılamadı; araç ve bağlantı yapılandırmasını kontrol edin.',503);}
        if(!$process->isSuccessful())throw new DomainError('YEDEK_BASARISIZ','Veritabanı yedeği alınamadı; başarı kaydı oluşturulmadı.',503);
        $private=config('filesystems.disks.local.root');$zipPath=$directory.DIRECTORY_SEPARATOR.'private.zip';$zip=new ZipArchive();
        if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new DomainError('YEDEK_BASARISIZ','Özel belge arşivi oluşturulamadı.',503);
        $files=[];
        if(is_dir($private))foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if(!$file->isFile()||$file->isLink())continue;
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen(rtrim($private,'/\\'))+1));
            if(!$zip->addFile($file->getPathname(),$relative))throw new DomainError('YEDEK_BASARISIZ','Belge arşive eklenemedi.',503);
            $files[$relative]=hash_file('sha256',$file->getPathname());
        }
        if(!$files)$zip->addFromString('.empty','');
        if(!$zip->close())throw new DomainError('YEDEK_BASARISIZ','Özel belge arşivi tamamlanamadı.',503);
        $manifest=['created_at'=>now()->toISOString(),'database_sha256'=>hash_file('sha256',$dump),'private_sha256'=>hash_file('sha256',$zipPath),'files'=>$files,'app_key_included'=>false];
        if(file_put_contents($directory.DIRECTORY_SEPARATOR.'manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))===false)throw new DomainError('YEDEK_BASARISIZ','Yedek özet dosyası yazılamadı.',503);
        return ['directory'=>$directory,'file_count'=>count($files),'database_sha256'=>$manifest['database_sha256']];
    }
}
