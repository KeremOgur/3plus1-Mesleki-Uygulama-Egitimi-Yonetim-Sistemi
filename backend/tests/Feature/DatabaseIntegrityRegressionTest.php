<?php
namespace Tests\Feature;

use App\Domain\Records;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixture as F;
use Tests\TestCase;

class DatabaseIntegrityRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_negative_orientation_hours_is_rejected_at_database_boundary(): void
    {
        $g=F::graph();$before=Records::query('risk_plans')->count();
        try{
            DB::transaction(fn()=>F::make('risk_plans',Records::scope($g['site'])+['site_id'=>$g['site']->id,'assessed_on'=>today()->toDateString(),'renewal_on'=>today()->addYear()->toDateString(),'signed_document_id'=>$g['doc']->id,'orientation_hours'=>-1]));
            $this->fail('Database accepted negative orientation hours');
        }catch(QueryException $e){$this->assertSame('23514',$e->errorInfo[0]);}
        $this->assertSame($before,Records::query('risk_plans')->count());
    }

    public function test_upgrade_restores_missing_check_and_is_idempotent_for_fresh_schema(): void
    {
        DB::statement('ALTER TABLE risk_plans DROP CONSTRAINT IF EXISTS risk_plans_orientation_hours_check');
        $migration=require database_path('migrations/2026_10_09_000800_restore_orientation_hours_check.php');
        $migration->up();$migration->up();
        $this->assertSame(1,(int)DB::selectOne("SELECT count(*) n FROM pg_constraint WHERE conrelid='risk_plans'::regclass AND conname='risk_plans_orientation_hours_check' AND convalidated")->n);
        $g=F::graph();
        foreach([null,0,1.5] as $hours){
            $plan=F::make('risk_plans',Records::scope($g['site'])+['site_id'=>$g['site']->id,'assessed_on'=>today()->toDateString(),'renewal_on'=>today()->addYear()->toDateString(),'signed_document_id'=>$g['doc']->id,'orientation_hours'=>$hours]);
            $this->assertEquals($hours,$plan->orientation_hours);
        }
    }

    public function test_present_json_fields_are_not_nullable_at_database_boundary(): void
    {
        foreach(config('domain') as $table=>$spec)foreach($spec['fields'] as $field=>$definition){
            if($definition['type']==='jsonb' && str_starts_with($definition['rules'],'present')){
                $column=DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?",[$table,$field]);
                $this->assertSame('NO',$column->is_nullable,$table.'.'.$field);
            }
        }
    }

    public function test_upgrade_enforces_present_json_without_replacing_existing_data(): void
    {
        $g=F::graph();$before=$g['app']->course_conditions;
        DB::statement('ALTER TABLE applications ALTER COLUMN course_conditions DROP NOT NULL');
        $migration=require database_path('migrations/2026_10_09_000900_enforce_present_json_fields.php');$migration->up();$migration->up();
        try{
            DB::transaction(fn()=>DB::table('applications')->where('id',$g['app']->id)->update(['course_conditions'=>null]));
            $this->fail('Database accepted NULL for present|array');
        }catch(QueryException $e){$this->assertSame('23502',$e->errorInfo[0]);}
        $this->assertSame($before,$g['app']->refresh()->course_conditions);
    }
}
