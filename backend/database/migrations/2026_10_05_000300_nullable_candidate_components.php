<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void { foreach(['rank','preference_score','transport_score','score'] as $field) DB::statement("ALTER TABLE candidate_scores ALTER COLUMN $field DROP NOT NULL"); }
 public function down(): void { throw new RuntimeException('Eksik verileri sıfıra dönüştürmeden yedekten geri dönüş uygulayın.'); }
};
