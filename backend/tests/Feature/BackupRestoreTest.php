<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Domain\{Backup,DomainError};
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
class BackupRestoreTest extends TestCase
{
    public function test_disabled_backup_does_not_report_success(): void
    {
        config(['backup.enabled'=>false]);$this->expectException(DomainError::class);app(Backup::class)->create();
    }
    public function test_dump_restore_and_private_file_hashes(): void
    {
        $bin=getenv('BACKUP_TEST_PG_BIN')?:'C:/Program Files/PostgreSQL/18/bin';$suffix=PHP_OS_FAMILY==='Windows'?'.exe':'';
        if(!is_file($bin.'/pg_dump'.$suffix))$this->markTestSkipped('PostgreSQL yedek/geri yükleme test araçları yapılandırılmadı.');
        $c=DB::connection()->getConfig();$this->assertSame('mue_test',$c['database']);
        config(['backup.enabled'=>true,'backup.pg_dump'=>$bin.'/pg_dump'.$suffix,'backup.directory'=>storage_path('app/backup-tests')]);
        $r=app(Backup::class)->create();$manifest=json_decode(file_get_contents($r['directory'].'/manifest.json'),true);
        $this->assertFalse($manifest['app_key_included']);$this->assertSame($manifest['database_sha256'],hash_file('sha256',$r['directory'].'/database.dump'));
        $zip=new \ZipArchive();$this->assertTrue($zip->open($r['directory'].'/private.zip')===true);foreach($manifest['files'] as $name=>$hash)$this->assertSame($hash,hash('sha256',$zip->getFromName($name)));$zip->close();
        $name='mue_restore_test_'.bin2hex(random_bytes(6));$this->assertMatchesRegularExpression('/^mue_restore_test_[a-f0-9]{12}$/',$name);
        $env=['PGHOST'=>$c['host'],'PGPORT'=>(string)$c['port'],'PGUSER'=>$c['username'],'PGPASSWORD'=>$c['password'],'PGSSLMODE'=>$c['sslmode']??'prefer'];
        $run=function(array $cmd)use($env){$p=new Process($cmd,null,$env,null,90);$p->run();$this->assertSame(0,$p->getExitCode(),'PostgreSQL geri yükleme aracı başarısız oldu.');};
        $run([$bin.'/createdb'.$suffix,$name]);
        try {
            $run([$bin.'/pg_restore'.$suffix,'--no-owner','--no-acl','--exit-on-error','--dbname='.$name,$r['directory'].'/database.dump']);
            config(['database.connections.restored'=>array_replace($c,['database'=>$name,'url'=>null])]);$restored=DB::connection('restored');
            foreach(['migrations','placements','audit_events','record_revisions','documents','role_assignments'] as $table)$this->assertSame(DB::table($table)->count(),$restored->table($table)->count(),$table);
            $this->assertGreaterThan(60,$restored->table('information_schema.tables')->where('table_schema','public')->count());
            $this->assertGreaterThan(0,$restored->selectOne("SELECT count(*) n FROM pg_constraint WHERE contype='f'")->n);
        } finally {DB::disconnect('restored');$run([$bin.'/dropdb'.$suffix,'--if-exists',$name]);}
    }
}
