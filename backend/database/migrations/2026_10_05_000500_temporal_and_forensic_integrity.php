<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
  DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
  DB::statement("ALTER TABLE placements ADD CONSTRAINT placement_dates_no_overlap EXCLUDE USING gist (student_id WITH =, term_id WITH =, daterange(starts_on,ends_on,'[]') WITH &&) WHERE (state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida','tamamlandi','degistirildi'))");
  $function=DB::selectOne("SELECT pg_get_functiondef('mue_history()'::regprocedure) AS body")->body;
  $function=str_replace(['md5(before_data::text)','md5(after_data::text)'],["encode(digest(before_data::text,'sha256'),'hex')","encode(digest(after_data::text,'sha256'),'hex')"],$function);
  DB::unprepared($function);
 }
 public function down(): void { throw new RuntimeException('Tarihsel veriler için yedekten geri dönüş uygulayın.'); }
};
