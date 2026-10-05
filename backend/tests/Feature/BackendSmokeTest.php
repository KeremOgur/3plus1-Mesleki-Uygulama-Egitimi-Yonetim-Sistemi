<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\Fixture;
use App\Domain\{Records,Eligibility,Matching,Education};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
class BackendSmokeTest extends TestCase
{
    use DatabaseTransactions;
    public function test_authentication_and_student_idor_and_technical_decision_scope(): void
    {
        $this->getJson('/api/v1/resources/students')->assertUnauthorized();
        $g=Fixture::graph(); Sanctum::actingAs($g['u']);
        $this->withHeader('X-Assignment-Id',$g['role']->id)->getJson('/api/v1/resources/students/'.$g['student']->id)->assertOk()->assertJsonPath('student_no',$g['student']->student_no);
        $other=Fixture::make('students',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'user_id'=>\App\Models\User::factory()->create()->id,'student_no'=>'OTHER','gpa_scale'=>4]);
        $this->getJson('/api/v1/resources/students/'.$other->id)->assertForbidden();
        $this->getJson('/api/v1/resources/students')->assertOk()->assertJsonPath('total',1);
        $technical=Fixture::make('role_assignments',['institution_id'=>$g['institution']->id,'user_id'=>$g['u']->id,'role'=>'sistem_yoneticisi','valid_from'=>now()->subDay(),'state'=>'aktif']);
        $this->withHeader('X-Assignment-Id',$technical->id)->withHeader('Idempotency-Key','technical-decision')->postJson('/api/v1/resources/company_assessments/'.$g['assessment']->id.'/transition',['version'=>$g['assessment']->version,'state'=>'onaylandi'])->assertStatus(409);
        $this->getJson('/api/v1/resources/applications/'.$g['app']->id)->assertForbidden();
    }
    public function test_last_capacity_and_duplicate_active_placement_are_database_invariants(): void
    {
        $g=Fixture::graph(); $scope=Records::scope($g['app']);
        $p=Fixture::make('placements',$scope+['application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'company_id'=>$g['company']->id,'starts_on'=>'2026-10-01','ends_on'=>'2027-01-14','state'=>'onaylandi']);
        $this->assertSame(1,Records::query('placements')->where('offer_id',$g['offer']->id)->count());
        try { DB::transaction(fn()=>Fixture::make('placements',$scope+['application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'company_id'=>$g['company']->id,'starts_on'=>'2026-10-01','ends_on'=>'2027-01-14','state'=>'onaylandi'])); $this->fail('Duplicate accepted'); } catch(\Illuminate\Database\QueryException $e) { $this->assertContains($e->getCode(),['P0001','23505']); }
        try { DB::transaction(fn()=>DB::table('offers')->where('id',$g['offer']->id)->update(['capacity'=>0])); $this->fail('Reduction accepted'); } catch(\Illuminate\Database\QueryException $e) { $this->assertSame('P0001',$e->getCode()); }
    }
    public function test_small_matching_fixture_and_independent_validation(): void
    {
        $g=Fixture::graph();
        $policy=Fixture::make('matching_policies',['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id,'term_id'=>$g['term']->id,'revision'=>1,'preference_weight'=>.75,'transport_weight'=>.25,'max_preferences'=>10,'transport_rules'=>[['max_minutes'=>30,'score'=>100],['max_minutes'=>60,'score'=>60],['score'=>20]],'seed'=>'fixture-2026','time_limit_seconds'=>10,'decision_document_id'=>$g['doc']->id,'state'=>'onaylandi']);
        $matching=app(Matching::class);$snapshot=$matching->snapshot($policy);
        $this->assertSame(100.0,$snapshot['candidates'][0]['score']);
        $process=new \Symfony\Component\Process\Process([config('mue.python'),config('mue.optimizer_runner')]);$process->setInput(json_encode($snapshot));$process->mustRun();$result=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('OPTIMAL',$result['solver_status']);$this->assertCount(1,$result['assignments']);$matching->validate($snapshot,$result);
        $run=Fixture::make('matching_runs',Records::scope($policy)+['policy_id'=>$policy->id,'snapshot'=>$snapshot,'snapshot_hash'=>$matching->hash($snapshot),'algorithm_version'=>'mue-cpsat-1.0','state'=>'tamamlandi']);
        $matching->assertFresh($run);$g['offer']->capacity=2;$g['offer']->save();
        $this->expectException(\App\Domain\DomainError::class);$matching->assertFresh($run);
    }
    public function test_absence_boundary_and_immutable_audit(): void
    {
        $g=Fixture::graph();$p=Fixture::make('placements',Records::scope($g['app'])+['application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>'2026-10-01','ends_on'=>'2027-01-14']);
        for($i=0;$i<75;$i++) Fixture::make('attendance',Records::scope($p)+['date'=>\Carbon\Carbon::parse('2026-10-01')->addDays($i)->toDateString(),'planned_minutes'=>480,'attended_minutes'=>$i<15?0:480,'shift_type'=>'gunduz','mark'=>$i<15?'Y':'V','state'=>'onaylandi']);
        $this->assertFalse(app(Education::class)->attendanceSummary($p)['failed']);
        $row=Records::query('attendance')->where('placement_id',$p->id)->where('attended_minutes',480)->first();$row->attended_minutes=420;$row->save();
        $this->assertTrue(app(Education::class)->attendanceSummary($p)['failed']);
        try { DB::transaction(fn()=>DB::table('audit_events')->update(['action'=>'tampered']));$this->fail('Audit mutable'); } catch(\Illuminate\Database\QueryException $e) {$this->assertSame('P0001',$e->getCode());}
    }
    public function test_success_uses_both_source_and_area_weights(): void
    {
        $g=Fixture::graph();$p=Fixture::make('placements',Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>'2026-10-01','ends_on'=>'2027-01-14']);
        for($i=0;$i<75;$i++)Fixture::make('attendance',Records::scope($p)+['date'=>\Carbon\Carbon::parse($p->starts_on)->addDays($i)->toDateString(),'planned_minutes'=>480,'attended_minutes'=>480,'shift_type'=>'gunduz','mark'=>'V','state'=>'onaylandi']);
        $catalog=['institution_id'=>$g['institution']->id,'program_id'=>$g['prog']->id];
        $output=Fixture::make('program_outputs',$catalog+['board_document_id'=>$g['doc']->id]);
        $outcome=Fixture::make('learning_outcomes',$catalog+['program_output_id'=>$output->id]);
        $rubric=Fixture::make('rubric_versions',$catalog+['board_document_id'=>$g['doc']->id,'state'=>'onaylandi']);
        foreach(['is_yeri'=>60,'danisman'=>80,'dosya_portfolyo'=>60,'sunum'=>100] as $source=>$value)foreach(['mesleki','problem','takim','etik','isg','belgeleme'] as $area)Fixture::make('rubric_evaluations',Records::scope($p)+['rubric_id'=>$rubric->id,'outcome_id'=>$outcome->id,'source'=>$source,'area'=>$area,'score'=>$source==='is_yeri'&&$area==='mesleki'?100:$value,'evidence_document_id'=>$g['doc']->id,'revision'=>1,'state'=>'onaylandi']);
        Fixture::make('training_file_deliveries',Records::scope($p)+['paper_delivered_on'=>$p->ends_on,'electronic_delivered_on'=>$p->ends_on,'electronic_document_id'=>$g['doc']->id,'trainer_approval_document_id'=>$g['doc']->id,'state'=>'onaylandi']);
        Fixture::make('presentations',Records::scope($p)+['document_id'=>$g['doc']->id,'commission_member_id'=>$g['u']->id,'state'=>'onaylandi']);
        $result=app(Education::class)->calculate($p);
        $this->assertEquals(72,$result->component_scores['is_yeri']);
        $this->assertEquals(74.8,$result->total_score);
        $this->assertEquals(7,$result->area_scores['isg']);
        $this->assertSame('basarili',$result->outcome);
    }
}
