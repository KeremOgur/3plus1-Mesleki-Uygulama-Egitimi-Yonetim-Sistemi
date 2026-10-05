<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  $columns=['companies'=>['tax_office'],'students'=>['profile_confirmed_at'],'learning_plans'=>['prepared_on','adviser_opinion_document_id'],'rubric_evaluations'=>['workplace_criteria_scores'],'self_assessments'=>['portfolio_platform','portfolio_integrity','evidence_consistency','adviser_feedback','consent_record_document_id'],'trainer_qualifications'=>['national_id','phone','email','education_records','experience_records'],'risk_plans'=>['student_units','orientation_hours','orientation_document_id'],'change_requests'=>['credit_reviewed_by']];
  foreach($columns as $table=>$fields)foreach($fields as $field)if(!Schema::hasColumn($table,$field))Schema::table($table,function(Blueprint $t)use($table,$field){$f=config("domain.$table.fields.$field");match($f['type']){'timestamp'=>$t->timestampTz($field)->nullable(),'date'=>$t->date($field)->nullable(),'jsonb'=>$t->jsonb($field)->nullable(),'text'=>$t->text($field)->nullable(),'uuid'=>$t->uuid($field)->nullable(),'decimal'=>$t->decimal($field,14,4)->nullable(),'bigint'=>$t->unsignedBigInteger($field)->nullable(),default=>$t->string($field,500)->nullable()};if($f['ref'])$t->foreign($field)->references('id')->on($f['ref'])->restrictOnDelete();});
  $body=DB::selectOne("SELECT pg_get_functiondef('mue_history()'::regprocedure) AS body")->body;
  $body=str_replace("ARRAY['state','version','updated_at']","ARRAY['state','version','updated_at','legal_hold','retained_until']",$body);
  DB::unprepared($body);
  DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION mue_frozen_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_state text; parent_id uuid;
BEGIN
 IF TG_TABLE_NAME IN ('plan_outcomes','weekly_plan_tasks') THEN
  parent_id:=NEW.plan_id; SELECT state INTO parent_state FROM learning_plans WHERE id=parent_id FOR UPDATE;
 ELSE
  parent_id:=NEW.report_id; SELECT state INTO parent_state FROM weekly_reports WHERE id=parent_id FOR UPDATE;
 END IF;
 IF parent_state NOT IN ('taslak','iade') THEN RAISE EXCEPTION 'Onay sürecindeki kaydın alt satırları değiştirilemez; yeni sürüm oluşturun'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER frozen_plan_outcomes BEFORE INSERT OR UPDATE ON plan_outcomes FOR EACH ROW EXECUTE FUNCTION mue_frozen_child();
CREATE TRIGGER frozen_weekly_plan_tasks BEFORE INSERT OR UPDATE ON weekly_plan_tasks FOR EACH ROW EXECUTE FUNCTION mue_frozen_child();
CREATE TRIGGER frozen_report_outcomes BEFORE INSERT OR UPDATE ON report_outcomes FOR EACH ROW EXECUTE FUNCTION mue_frozen_child();
SQL);
 }
 public function down(): void { throw new RuntimeException('Tarihsel kayıtlar için belgelenmiş yedekten geri dönüş uygulayın.'); }
};
