<?php
namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\Fixture as F;
use App\Domain\{Records,Matching,Education,DomainError};
use App\Jobs\RunMatching;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Queue,Storage};
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

class FinalSystemQaTest extends TestCase
{
    use DatabaseTransactions;

    private function login(array $g,string $role='ogrenci'): void
    {
        Sanctum::actingAs($g['u']);
        $a=$role==='ogrenci'?$g['role']:F::make('role_assignments',['institution_id'=>$g['institution']->id,'user_id'=>$g['u']->id,'role'=>$role,'valid_from'=>now()->subDay(),'state'=>'aktif']);
        $this->withHeaders(['X-Assignment-Id'=>$a->id,'Idempotency-Key'=>(string)Str::uuid()]);
    }

    public function test_history_hydrates_json_and_encrypted_fields_and_keeps_role_redaction(): void
    {
        $g=F::graph();$g['student']->iban='TR330006100519786457841326';$g['student']->academic_data=['courses'=>['Test']];$g['student']->save();
        $this->login($g);
        $this->getJson('/api/v1/resources/students/'.$g['student']->id.'/history')->assertOk()->assertJsonPath('1.record.academic_data.courses.0','Test')->assertJsonPath('1.record.iban','TR330006100519786457841326');
        $this->getJson('/api/v1/resources/academic_terms/'.$g['term']->id.'/history')->assertOk()->assertJsonPath('0.record.letter_grade_rules.0.letter','CC');
        $g['company']->iban='TR330006100519786457841326';$g['company']->save();
        $history=$this->getJson('/api/v1/resources/companies/'.$g['company']->id.'/history')->assertOk()->json();
        foreach($history as $revision)$this->assertArrayNotHasKey('iban',$revision['record']);
    }

    public function test_malformed_route_ids_filters_and_assignment_never_reach_postgres_as_uuid(): void
    {
        $g=F::graph();$this->login($g,'mudur');
        foreach(['student_id','institution_id','program_id','term_id','company_id','placement_id','user_id','cursor'] as $field)
            $this->getJson('/api/v1/resources/students?'.$field.'=not-a-uuid')->assertStatus(422);
        $this->getJson('/api/v1/resources/students/'.$g['student']->id.'?ignored=true')->assertOk();
        $this->getJson('/api/v1/resources/students/not-a-uuid')->assertNotFound();
        $this->postJson('/api/v1/resources/students/not-a-uuid/transition',['version'=>1,'state'=>'taslak'])->assertNotFound();
        $this->getJson('/api/v1/resources/students?limit=banana')->assertStatus(422);
        foreach(['not-an-integer','-1','9223372036854775808'] as $id){$this->withHeader('Idempotency-Key',(string)Str::uuid());$this->postJson('/api/v1/accounts/'.$id.'/deactivate',['reason'=>'Sentetik QA'])->assertNotFound();}
        foreach(['q','state','form_code'] as $field)$this->getJson('/api/v1/resources/students?'.$field.'[]=invalid')->assertStatus(422);
        $this->withHeader('X-Assignment-Id','not-a-uuid')->getJson('/api/v1/resources/students')->assertForbidden();
    }

    public function test_valid_upload_formats_and_mime_size_empty_and_foreign_target_rejection(): void
    {
        Queue::fake();Storage::fake('local');$g=F::graph();$this->login($g);
        $path=tempnam(sys_get_temp_dir(),'mue-docx');$zip=new \ZipArchive();$zip->open($path,\ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml','<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/></w:body></w:document>');$zip->close();
        try {
            $files=[UploadedFile::fake()->image('valid.png'),UploadedFile::fake()->image('valid.jpg'),UploadedFile::fake()->createWithContent('valid.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),UploadedFile::fake()->createWithContent('valid.docx',file_get_contents($path))];
            foreach($files as $file){$this->withHeader('Idempotency-Key',(string)Str::uuid());$doc=$this->postJson('/api/v1/documents',['file'=>$file,'target_type'=>'students','target_id'=>$g['student']->id,'classification'=>'kurum_ici','retention_start_event'=>'test'])->assertCreated()->assertJsonPath('scan_status','bekliyor')->assertJsonMissingPath('object_key')->json();
                $stored=Records::get('documents',$doc['id']);Storage::disk('local')->assertExists($stored->object_key);$this->assertSame($stored->sha256,hash('sha256',Storage::disk('local')->get($stored->object_key)));$this->getJson('/api/v1/documents/'.$doc['id'].'/download')->assertStatus(409)->assertJsonPath('code','DOSYA_KARANTINA');}
            foreach([UploadedFile::fake()->createWithContent('wrong.exe',"%PDF-1.4\n%%EOF"),UploadedFile::fake()->createWithContent('wrong.png','<?php echo 1;'),UploadedFile::fake()->createWithContent('empty.pdf',''),UploadedFile::fake()->create('large.pdf',20481,'application/pdf')] as $file){$this->withHeader('Idempotency-Key',(string)Str::uuid());$this->postJson('/api/v1/documents',['file'=>$file,'target_type'=>'students','target_id'=>$g['student']->id,'classification'=>'kurum_ici','retention_start_event'=>'test'])->assertStatus(422);}
            $other=F::graph();$this->withHeader('Idempotency-Key',(string)Str::uuid());$this->postJson('/api/v1/documents',['file'=>UploadedFile::fake()->image('foreign.png'),'target_type'=>'students','target_id'=>$other['student']->id,'classification'=>'kurum_ici','retention_start_event'=>'test'])->assertForbidden();
        } finally {unlink($path);}
    }

    public function test_optimizer_failure_and_invalid_result_leave_no_partial_scores(): void
    {
        $g=F::graph();$policy=F::make('matching_policies',Records::scope($g['app'])+['revision'=>1,'preference_weight'=>.75,'transport_weight'=>.25,'max_preferences'=>5,'transport_rules'=>[['score'=>100]],'seed'=>'qa-failure','time_limit_seconds'=>10,'decision_document_id'=>$g['doc']->id,'state'=>'onaylandi']);
        $matching=app(Matching::class);$snapshot=$matching->snapshot($policy);
        $run=F::make('matching_runs',Records::scope($policy)+['policy_id'=>$policy->id,'snapshot'=>$snapshot,'snapshot_hash'=>$matching->hash($snapshot),'algorithm_version'=>$snapshot['algorithm_version'],'state'=>'kuyrukta']);
        config(['mue.python'=>base_path('storage/nonexistent-python-for-qa.exe')]);
        (new RunMatching($run->id))->handle($matching);
        $this->assertSame('basarisiz',$run->refresh()->state);$this->assertNotEmpty($run->failure_message);
        $this->assertSame(0,Records::query('candidate_scores')->where('run_id',$run->id)->count());$this->assertSame(0,Records::query('match_results')->where('run_id',$run->id)->count());
        $result=['solver_status'=>'OPTIMAL','algorithm_version'=>$snapshot['algorithm_version'],'assignments'=>[['student_id'=>$g['app']->id,'offer_id'=>$g['offer']->id]],'unplaced'=>[]];
        foreach(['duplicate','capacity','ineligible','missing','fixed','unknown'] as $case){$s=$snapshot;$r=$result;
            if($case==='duplicate')$r['assignments'][]=$r['assignments'][0];
            if($case==='capacity')$s['offers'][0]['capacity']=0;
            if($case==='ineligible')$s['candidates'][0]['eligible']=false;
            if($case==='missing')$r['assignments']=[];
            if($case==='fixed')$s['students'][0]['fixed_offer_id']=$g['offer']->id;
            if($case==='unknown')$r['assignments'][0]['student_id']=(string)Str::uuid();
            try{$matching->validate($s,$r);$this->fail('Invalid optimizer result accepted: '.$case);}catch(DomainError $e){$this->assertSame('RUN_INVALID',$e->errorCode);}
        }
    }

    private function gradeFixture(float|array|null $uniform=null,string $deliveryState='onaylandi',bool $omitArea=false): array
    {
        $g=F::graph();$p=F::make('placements',Records::scope($g['app'])+['company_id'=>$g['company']->id,'application_id'=>$g['app']->id,'offer_id'=>$g['offer']->id,'starts_on'=>$g['term']->starts_on,'ends_on'=>$g['term']->ends_on]);
        foreach(range(0,74) as $i)F::make('attendance',Records::scope($p)+['date'=>\Carbon\Carbon::parse($p->starts_on)->addDays($i)->toDateString(),'planned_minutes'=>480,'attended_minutes'=>480,'shift_type'=>'gunduz','mark'=>'V','state'=>'onaylandi']);
        $output=F::make('program_outputs',Records::scope($g['prog'])+['board_document_id'=>$g['doc']->id]);$outcome=F::make('learning_outcomes',Records::scope($output)+['program_output_id'=>$output->id]);$rubric=F::make('rubric_versions',Records::scope($output)+['board_document_id'=>$g['doc']->id,'state'=>'onaylandi']);
        $evaluations=[];foreach(['is_yeri'=>100,'danisman'=>80,'dosya_portfolyo'=>60,'sunum'=>40] as $source=>$score)foreach(['mesleki','problem','takim','etik','isg','belgeleme'] as $area){if($omitArea && $source==='is_yeri' && $area==='mesleki')continue;$evaluations[]=F::make('rubric_evaluations',Records::scope($p)+['rubric_id'=>$rubric->id,'outcome_id'=>$outcome->id,'source'=>$source,'area'=>$area,'score'=>is_array($uniform)?$uniform[$source]:($uniform??$score),'evidence_document_id'=>$g['doc']->id,'revision'=>1,'state'=>'onaylandi']);}
        $delivery=F::make('training_file_deliveries',Records::scope($p)+['paper_delivered_on'=>$p->ends_on,'electronic_delivered_on'=>$p->ends_on,'electronic_document_id'=>$g['doc']->id,'trainer_approval_document_id'=>$g['doc']->id,'state'=>$deliveryState]);F::make('presentations',Records::scope($p)+['commission_member_id'=>$g['u']->id,'document_id'=>$g['doc']->id,'state'=>'onaylandi']);
        return [$g,$p,$evaluations,$delivery];
    }

    public function test_success_source_weights_threshold_missing_component_and_late_file(): void
    {
        [$g,$p,$rows,$delivery]=$this->gradeFixture();$education=app(Education::class);
        $this->assertEquals(80,$education->calculate($p)->total_score);
        [, $p]=$this->gradeFixture(60);$result=$education->calculate($p);$this->assertEquals(60,$result->total_score);$this->assertSame('basarili',$result->outcome);
        [, $p]=$this->gradeFixture(59.99);$this->assertSame('basarisiz',$education->calculate($p)->outcome);
        [, $p]=$this->gradeFixture(100);$result=$education->calculate($p);$this->assertEquals(100,$result->total_score);$this->assertSame('basarili',$result->outcome);
        [, $p]=$this->gradeFixture(0);$result=$education->calculate($p);$this->assertEquals(0,$result->total_score);$this->assertSame('basarisiz',$result->outcome);
        [, $p]=$this->gradeFixture(100,'taslak');$result=$education->calculate($p);$this->assertEquals(80,$result->total_score);$this->assertEquals(0,$result->component_scores['dosya_portfolyo']);$this->assertNotEmpty($result->reasons);
        [, $p]=$this->gradeFixture(100,'onaylandi',true);try{$education->calculate($p);$this->fail('Missing component accepted');}catch(DomainError $e){$this->assertSame('EKSIK_KAZANIM',$e->errorCode);}
    }

    public function test_unapproved_rubric_component_is_rejected_and_late_delivery_loses_file_weight(): void
    {
        [, $p,$rows]=$this->gradeFixture(100);
        foreach($rows as $row)if($row->source==='danisman'){$row->state='taslak';$row->save();}
        try{app(Education::class)->calculate($p);$this->fail('Unapproved component accepted');}catch(DomainError $e){$this->assertSame('EKSIK_BILESEN',$e->errorCode);}
        [, $p,,$delivery]=$this->gradeFixture(100);
        $delivery->paper_delivered_on=\Carbon\Carbon::parse($p->ends_on)->addDays(40)->toDateString();$delivery->save();
        $result=app(Education::class)->calculate($p);$this->assertEquals(80,$result->total_score);$this->assertEquals(0,$result->component_scores['dosya_portfolyo']);$this->assertNotEmpty($result->reasons);
    }

    public function test_individual_source_weights_are_40_30_20_10(): void
    {
        foreach(['is_yeri'=>40,'danisman'=>30,'dosya_portfolyo'=>20,'sunum'=>10] as $source=>$expected){
            [, $p]=$this->gradeFixture(array_replace(array_fill_keys(['is_yeri','danisman','dosya_portfolyo','sunum'],0),[$source=>100]));
            $this->assertEquals($expected,app(Education::class)->calculate($p)->total_score,$source);
        }
    }

    public function test_missing_scanner_does_not_mark_a_document_clean(): void
    {
        $g=F::graph();$g['doc']->scan_status='bekliyor';$g['doc']->state='karantina';$g['doc']->save();config(['mue.scanner'=>null]);
        try{(new \App\Jobs\ScanDocument($g['doc']->id))->handle();$this->fail('Unconfigured scanner accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('karantinada',$e->getMessage());}
        $this->assertSame('bekliyor',$g['doc']->refresh()->scan_status);
    }

    public function test_database_connection_failure_is_controlled_for_json_and_html(): void
    {
        config(['app.debug'=>false]);
        $failure=function(){ $previous=new \PDOException('Private connection details must not leak');$previous->errorInfo=['08006',7,'connection refused'];throw new \Illuminate\Database\QueryException('pgsql','select private_data',[],$previous); };
        \Illuminate\Support\Facades\Route::get('/api/qa-connection-failure',$failure);
        \Illuminate\Support\Facades\Route::get('/qa-connection-failure',$failure);
        $response=$this->getJson('/api/qa-connection-failure')->assertStatus(503)->assertJsonPath('code','VERITABANI_ERISILEMIYOR')->assertDontSee('Private connection details');
        $this->assertInstanceOf(\stdClass::class,json_decode($response->getContent())->field_errors);
        $this->get('/qa-connection-failure')->assertStatus(503)->assertDontSee('Private connection details');
    }

    public function test_institutional_interfaces_and_unconfigured_smtp_fail_explicitly(): void
    {
        $integration=new \App\Integrations\UnavailableIntegration();
        foreach([fn()=>$integration->fetchStudents('TEST','TEST','TEST'),fn()=>$integration->submitGrade('TEST','TEST',80,'TEST'),fn()=>$integration->verify('TEST','TEST'),fn()=>$integration->send('TEST','TEST','TEST'),fn()=>$integration->submit('TEST','TEST'),fn()=>$integration->status('TEST')] as $call){
            try{$call();$this->fail('Unavailable institution interface reported success');}catch(DomainError $e){$this->assertSame(503,$e->httpStatus);$this->assertSame('ENTEGRASYON_YOK',$e->errorCode);}
        }
        config(['mail.default'=>'log']);$this->postJson('/api/v1/auth/forgot-password',['email'=>'qa.smtp@demo.mue.invalid'])->assertStatus(503)->assertJsonPath('code','EPOSTA_YAPILANDIRILMADI');
    }

    public function test_profile_rejects_bad_iban_checksum_and_accepts_valid_account(): void
    {
        $g=F::graph();$this->login($g);
        foreach(['TR000000000000000000000000','TR330006100519786457841327','not-an-iban'] as $iban){$this->withHeader('Idempotency-Key',(string)Str::uuid());$this->postJson('/api/v1/students/'.$g['student']->id.'/confirm-profile',['version'=>$g['student']->version,'iban'=>$iban])->assertStatus(422);}
        $this->assertNull($g['student']->refresh()->iban);
        $this->withHeader('Idempotency-Key',(string)Str::uuid());$this->postJson('/api/v1/students/'.$g['student']->id.'/confirm-profile',['version'=>$g['student']->version,'iban'=>'TR330006100519786457841326'])->assertOk()->assertJsonPath('iban','TR330006100519786457841326');
    }

    public function test_critical_form_validation_rejects_invalid_fields_without_writing(): void
    {
        $g=F::graph();$this->login($g,'koordinator');
        $base=$g['company']->toArray();$base['email']='qa.forms@demo.mue.invalid';$base['tax_no']='QA-'.Str::random(8);
        $before=Records::query('companies')->count();
        $cases=[['legal_name'=>''],['email'=>'invalid-email'],['personnel_count'=>-1],['organization_type'=>'unexpected'],['legal_name'=>str_repeat('x',251)],['iban'=>'TR000000000000000000000000'],['institution_id'=>'malformed']];
        foreach($cases as $change){$this->withHeader('Idempotency-Key',(string)Str::uuid());$this->postJson('/api/v1/resources/companies',array_replace($base,$change))->assertStatus(422);}
        $this->assertSame($before,Records::query('companies')->count());
        $this->login($g,'mudur');
        foreach([['starts_on'=>'not-a-date'],['pass_threshold'=>-1]] as $change){$this->withHeader('Idempotency-Key',(string)Str::uuid());$this->putJson('/api/v1/resources/academic_terms/'.$g['term']->id,array_replace(['version'=>$g['term']->version,'reason'=>'Sentetik QA'], $change))->assertStatus(422);}
        $this->withHeader('Idempotency-Key',(string)Str::uuid());$this->putJson('/api/v1/resources/academic_terms/'.$g['term']->id,['version'=>$g['term']->version,'reason'=>'Sentetik QA','starts_on'=>'2028-01-01','ends_on'=>'2027-01-01'])->assertStatus(422);
        $this->assertSame('2026-10-01',$g['term']->refresh()->starts_on);
    }

    public function test_portal_user_reference_names_remain_scoped(): void
    {
        $g=F::graph();$foreign=F::graph();
        $director=F::make('role_assignments',['institution_id'=>$g['institution']->id,'user_id'=>$g['u']->id,'role'=>'mudur','valid_from'=>now()->subDay(),'state'=>'aktif']);
        $this->actingAs($g['u'])->withSession(['assignment_id'=>$director->id,'password_hash_web'=>$g['u']->password]);
        $items=$this->getJson('/portal-api/v1/reference-users')->assertOk()->json('items');
        $this->assertContains($g['u']->id,array_column($items,'id'));$this->assertNotContains($foreign['u']->id,array_column($items,'id'));
        $this->assertSame($g['u']->name,$items[0]['name']);
        $this->withSession(['assignment_id'=>$g['role']->id]);
        $this->getJson('/portal-api/v1/reference-users')->assertOk()->assertJsonCount(1,'items')->assertJsonPath('items.0.id',$g['u']->id);
    }
}
