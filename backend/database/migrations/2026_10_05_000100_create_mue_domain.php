<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('MUE şeması PostgreSQL gerektirir.');
        }
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
            $t->string('external_subject')->nullable()->unique();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable(); $t->timestampsTz();
        });
        foreach (config('domain') as $name => $spec) {
            Schema::create($name, function (Blueprint $t) use ($name, $spec) {
                $t->uuid('id')->primary();
                foreach (['institution_id', 'program_id', 'term_id', 'company_id', 'student_id', 'placement_id'] as $context) {
                    if ($name !== 'institutions') $t->uuid($context)->nullable()->index();
                }
                $t->string('state')->default($name === 'placements' ? 'onerildi' : ($name === 'appeals' ? 'alindi' : ($name === 'change_requests' ? 'talep' : 'taslak')));
                $t->integer('version')->default(1); $t->unsignedBigInteger('created_by')->nullable();
                $t->timestampTz('retained_until')->nullable(); $t->boolean('legal_hold')->default(false);
                $t->timestampsTz();
                foreach ($spec['fields'] as $field => $def) {
                    if ($field === 'legal_hold') continue;
                    match ($def['type']) {
                        'string' => $t->string($field, 500)->nullable(),
                        'text' => $t->text($field)->nullable(),
                        'integer' => $t->integer($field)->nullable(),
                        'bigint' => $t->unsignedBigInteger($field)->nullable(),
                        'decimal' => $t->decimal($field, 14, 4)->nullable(),
                        'date' => $t->date($field)->nullable(),
                        'timestamp' => $t->timestampTz($field)->nullable(),
                        'boolean' => $t->boolean($field)->nullable(),
                        'jsonb' => $t->jsonb($field)->nullable(),
                        'uuid' => $t->uuid($field)->nullable(),
                    };
                }
                foreach ($spec['unique'] as $i => $cols) $t->unique($cols, substr($name, 0, 30).'_uq_'.$i);
            });
        }
        Schema::create('record_revisions', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('record_type'); $t->uuid('record_id'); $t->integer('version');
            $t->jsonb('snapshot'); $t->timestampTz('recorded_at')->useCurrent();
            $t->unique(['record_type', 'record_id', 'version']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('actor_id')->nullable();
            $t->uuid('assignment_id')->nullable(); $t->uuid('institution_id')->nullable();
            $t->string('action'); $t->string('target_type'); $t->uuid('target_id')->nullable();
            $t->string('request_id')->nullable(); $t->text('reason')->nullable();
            $t->string('before_hash')->nullable(); $t->string('after_hash')->nullable();
            $t->timestampTz('occurred_at')->useCurrent();
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('operation'); $t->string('key');
            $t->string('request_hash'); $t->jsonb('response')->nullable(); $t->integer('http_status')->default(200);
            $t->timestampsTz(); $t->unique(['user_id','operation','key']);
        });
        $contexts = ['institution_id'=>'institutions','program_id'=>'programs','term_id'=>'academic_terms','company_id'=>'companies','student_id'=>'students','placement_id'=>'placements'];
        foreach (config('domain') as $name => $spec) {
            Schema::table($name, function (Blueprint $t) use ($name, $spec, $contexts) {
                $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                if ($name !== 'institutions') foreach ($contexts as $field=>$parent) $t->foreign($field, substr($name,0,26).'_'.$field.'_fk')->references('id')->on($parent)->restrictOnDelete();
                foreach ($spec['fields'] as $field=>$def) if ($def['ref']) $t->foreign($field, substr($name,0,24).'_'.$field.'_fk')->references('id')->on($def['ref'])->restrictOnDelete();
            });
            if ($name !== 'institutions') DB::statement("ALTER TABLE $name ALTER COLUMN institution_id SET NOT NULL");
            foreach ($spec['fields'] as $field=>$def) {
                if (str_starts_with($def['rules'], 'required') || str_starts_with($def['rules'], 'present')) DB::statement("ALTER TABLE $name ALTER COLUMN $field SET NOT NULL");
                if (in_array($def['type'], ['integer','decimal'])) DB::statement("ALTER TABLE $name ADD CHECK ($field >= 0)");
            }
            if ($spec['states']) {
                $states = array_unique(array_merge(array_keys($spec['states']), ...array_values($spec['states'])));
                $vals = implode(',', array_map(fn($s)=>"'$s'", $states));
                DB::statement("ALTER TABLE $name ADD CHECK (state IN ($vals))");
            }
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION mue_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Tarihsel kayıt değiştirilemez veya silinemez'; END $$;
CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION mue_immutable();
CREATE TRIGGER revisions_immutable BEFORE UPDATE OR DELETE ON record_revisions FOR EACH ROW EXECUTE FUNCTION mue_immutable();
CREATE OR REPLACE FUNCTION mue_history() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE before_data jsonb; after_data jsonb;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'İş kayıtları silinemez; durum değişikliği kullanın'; END IF;
 IF TG_OP = 'UPDATE' THEN
   before_data := to_jsonb(OLD);
   NEW.version := OLD.version + 1;
   IF TG_TABLE_NAME IN ('decisions','publications','publication_items','candidate_scores','match_results') THEN RAISE EXCEPTION 'Kesin tarihsel kayıt için yeni sürüm oluşturun'; END IF;
   IF TG_TABLE_NAME = 'matching_runs' AND OLD.state IN ('tamamlandi','basarisiz') THEN RAISE EXCEPTION 'Eşleştirme sonucu değiştirilemez'; END IF;
   IF TG_TABLE_NAME = 'matching_policies' AND OLD.state = 'onaylandi' THEN RAISE EXCEPTION 'Onaylı politika değiştirilemez'; END IF;
   IF TG_TABLE_NAME IN ('learning_plans','weekly_reports','rubric_evaluations','success_results') AND OLD.state IN ('onaylandi','danisman_onayi','ilan_edildi') AND (to_jsonb(NEW)-ARRAY['state','version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','version','updated_at']) THEN RAISE EXCEPTION 'Onaylı kayıt için yeni sürüm oluşturun'; END IF;
 END IF;
 after_data := to_jsonb(NEW);
 INSERT INTO record_revisions(record_type,record_id,version,snapshot) VALUES(TG_TABLE_NAME,NEW.id,NEW.version,after_data);
 INSERT INTO audit_events(actor_id,assignment_id,institution_id,action,target_type,target_id,request_id,reason,before_hash,after_hash)
 VALUES(NULLIF(current_setting('mue.actor',true),'')::bigint,NULLIF(current_setting('mue.assignment',true),'')::uuid,NULLIF(after_data->>'institution_id','')::uuid,TG_OP,TG_TABLE_NAME,NEW.id,current_setting('mue.request',true),current_setting('mue.reason',true),md5(before_data::text),md5(after_data::text));
 RETURN NEW;
END $$;
CREATE UNIQUE INDEX one_active_placement ON placements(student_id,term_id) WHERE state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
ALTER TABLE attendance ADD CHECK (planned_minutes > 0 AND attended_minutes <= planned_minutes AND shift_type = 'gunduz');
ALTER TABLE preferences ADD CHECK (rank > 0 AND revision > 0);
ALTER TABLE matching_policies ADD CHECK (abs(preference_weight + transport_weight - 1) < 0.00001);
ALTER TABLE academic_terms ADD CHECK (ends_on > starts_on AND preference_deadline > preference_opens_at);
ALTER TABLE protocols ADD CHECK (valid_until > valid_from);
ALTER TABLE placements ADD CHECK (ends_on >= starts_on);
CREATE OR REPLACE FUNCTION mue_capacity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o offers%ROWTYPE; s company_sites%ROWTYPE; n integer;
BEGIN
 IF NEW.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida') THEN
  SELECT * INTO o FROM offers WHERE id=NEW.offer_id;
  SELECT * INTO s FROM company_sites WHERE id=o.site_id FOR UPDATE;
  PERFORM 1 FROM offers WHERE id=NEW.offer_id FOR UPDATE;
  SELECT count(*) INTO n FROM placements WHERE offer_id=NEW.offer_id AND id<>NEW.id AND state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
  IF n >= o.capacity THEN RAISE EXCEPTION 'Kontenjan dolu'; END IF;
  IF s.capacity_type='ortak' THEN
    SELECT count(*) INTO n FROM placements p JOIN offers x ON x.id=p.offer_id WHERE x.site_id=s.id AND p.term_id=NEW.term_id AND p.id<>NEW.id AND p.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
    IF s.total_capacity IS NULL OR n>=s.total_capacity THEN RAISE EXCEPTION 'Şubenin ortak kontenjanı dolu veya belirlenmedi'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER placement_capacity BEFORE INSERT OR UPDATE ON placements FOR EACH ROW EXECUTE FUNCTION mue_capacity();
CREATE OR REPLACE FUNCTION mue_capacity_reduction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE n integer;
BEGIN
 SELECT count(*) INTO n FROM placements WHERE offer_id=NEW.id AND state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
 IF NEW.capacity<n THEN RAISE EXCEPTION 'Kontenjan mevcut yerleştirmelerin altına düşürülemez'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER offer_capacity_reduction BEFORE UPDATE ON offers FOR EACH ROW EXECUTE FUNCTION mue_capacity_reduction();
CREATE OR REPLACE FUNCTION mue_trainer_limit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE n integer;
BEGIN
 PERFORM 1 FROM trainer_qualifications WHERE id=NEW.trainer_id FOR UPDATE;
 SELECT count(*) INTO n FROM trainer_assignments a JOIN placements p ON p.id=a.placement_id WHERE a.trainer_id=NEW.trainer_id AND a.id<>NEW.id AND p.term_id=NEW.term_id AND p.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
 IF n>=5 THEN RAISE EXCEPTION 'Eğitici başına en fazla beş öğrenci atanabilir'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER trainer_student_limit BEFORE INSERT OR UPDATE ON trainer_assignments FOR EACH ROW EXECUTE FUNCTION mue_trainer_limit();
SQL);
        // Every FK is checked for institution consistency in PostgreSQL as well as Laravel.
        foreach (config('domain') as $name=>$spec) {
            DB::unprepared("CREATE TRIGGER history BEFORE INSERT OR UPDATE OR DELETE ON $name FOR EACH ROW EXECUTE FUNCTION mue_history()");
            if ($name === 'institutions') continue;
            $checks = '';
            $refs = array_merge($contexts, array_filter(array_map(fn($f)=>$f['ref'], $spec['fields'])));
            foreach ($refs as $field=>$ref) {
                if ($ref === 'users' || $ref === 'institutions') continue;
                $checks .= "IF NEW.$field IS NOT NULL AND NOT EXISTS(SELECT 1 FROM $ref WHERE id=NEW.$field AND institution_id=NEW.institution_id) THEN RAISE EXCEPTION 'Kurum kapsamı uyuşmuyor: $field'; END IF;\n";
            }
            DB::unprepared("CREATE FUNCTION scope_$name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN $checks RETURN NEW; END \$\$; CREATE TRIGGER scope_check BEFORE INSERT OR UPDATE ON $name FOR EACH ROW EXECUTE FUNCTION scope_$name();");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Tarihsel kayıt kaybını önlemek için yedekten geri dönüş planını uygulayın.');
    }
};
