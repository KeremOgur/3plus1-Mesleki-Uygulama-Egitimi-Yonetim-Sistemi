<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('users',function(Blueprint $t){$t->text('mfa_secret')->nullable();$t->bigInteger('mfa_last_counter')->nullable();$t->timestampTz('mfa_confirmed_at')->nullable();});}
 public function down(): void {throw new RuntimeException('Güvenlik şeması geri dönüşü yedekten ayrı ortama yapılmalıdır.');}
};
