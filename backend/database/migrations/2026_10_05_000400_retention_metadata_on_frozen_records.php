<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  $function=DB::selectOne("SELECT pg_get_functiondef('mue_history()'::regprocedure) AS body")->body;
  $function=str_replace("ARRAY['state','version','updated_at']","ARRAY['state','version','updated_at','legal_hold','retained_until']",$function);
  DB::unprepared($function);
 }
 public function down(): void { throw new RuntimeException('Saklama kayıtları için yedekten geri dönüş uygulayın.'); }
};
