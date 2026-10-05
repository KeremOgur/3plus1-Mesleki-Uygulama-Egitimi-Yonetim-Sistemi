<?php
namespace App\Http\Controllers;
use App\Domain\{Records,ScopeAccess,DomainError,Eligibility,Workflow};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class AdministrativeController extends ResourceController
{
    public function confirmProfile(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','phone'=>'nullable|string|max:30','address'=>'nullable|string|max:20000','iban'=>'nullable|string|regex:/^TR[0-9]{24}$/']);
        return $this->transaction($req,function()use($req,$id){$s=Records::query('students')->lockForUpdate()->findOrFail($id);Gate::authorize('record-view',$s);app(ScopeAccess::class)->assertAction('ogrenci',$s);$this->version($req,$s);$s->fill($req->only('phone','address','iban'));$s->profile_confirmed_at=now();$s->save();return $s->refresh();});
    }
    public function revoke(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','reason'=>'required|string']);
        return $this->transaction($req,function($a)use($req,$id){
            $r=Records::query('role_assignments')->lockForUpdate()->findOrFail($id);$this->version($req,$r);
            if($r->institution_id!==$a->institution_id || ($r->role==='sistem_yoneticisi'?$a->role!=='sistem_yoneticisi':$a->role!=='mudur'))throw new DomainError('FORBIDDEN_SCOPE','Bu görevlendirmeyi kaldırmaya yetkiniz yok.',403);
            $r->valid_until=now();$r->state='pasif';$r->save();return ['message'=>'Görevlendirme kaldırıldı.'];
        });
    }
    public function renewProtocol(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','reason'=>'required|string']);
        return $this->transaction($req,function()use($req,$id){
            $r=Records::query('protocols')->lockForUpdate()->findOrFail($id);app(ScopeAccess::class)->assertAction('mudur',$r);$this->version($req,$r);
            if($r->state!=='aktif' || $r->termination_notice_on || Records::get('companies',$r->company_id)->state!=='aktif')throw new DomainError('PROTOKOL_UZATILAMAZ','Fesih bildirimi bulunan veya aktif olmayan protokol uzatılamaz.');
            if(now()->lessThan(\Carbon\Carbon::parse($r->valid_until)->subDays(30)))throw new DomainError('PROTOKOL_SURESI','Yenileme otuz günlük fesih bildirim dönemi içinde değerlendirilir.');
            $attrs=$r->only(array_keys(config('domain.protocols.fields')));$attrs['valid_from']=$r->valid_until;$attrs['valid_until']=\Carbon\Carbon::parse($r->valid_until)->addYear()->toDateString();$attrs['supersedes_id']=$r->id;
            $new=Records::create('protocols',array_merge(Records::scope($r),$attrs,['state'=>'aktif']));
            foreach(Records::query('protocol_scopes')->where('protocol_id',$r->id)->get() as $scope)Records::create('protocol_scopes',array_merge(Records::scope($scope),['protocol_id'=>$new->id,'site_id'=>$scope->site_id]));
            return response()->json($new,201);
        });
    }
    public function interviewSummary(Request $req,string $id)
    {
        $r=Records::get('interviews',$id);Gate::authorize('record-view',$r);$student=Records::get('students',$r->student_id);$u=\App\Models\User::findOrFail($student->user_id);
        return ['student_no'=>$student->student_no,'name'=>$u->name,'program'=>Records::get('programs',$student->program_id)->name,'interview_result'=>$r->result];
    }
    public function changeCredits(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','credited_minutes'=>'required|integer|min:0|max:36000','credited_outcomes'=>'present|array','credited_outcomes.*.outcome_id'=>'required|uuid','credited_outcomes.*.evidence'=>'required|string','reason'=>'required|string','directorate_document_id'=>'nullable|uuid']);
        return $this->transaction($req,function($a)use($req,$id){
            $r=Records::query('change_requests')->lockForUpdate()->findOrFail($id);Gate::authorize('record-view',$r);$this->version($req,$r);app(ScopeAccess::class)->assertAction('akademik_danisman mue_komisyon mudur',$r);
            if($a->role==='akademik_danisman') { foreach($req->credited_outcomes as $row){$outcome=Records::get('learning_outcomes',$row['outcome_id']);Gate::authorize('record-view',$outcome);if($outcome->program_id!==$r->program_id)throw new DomainError('MAHSUP_KAZANIMI','Mahsup kazanımı öğrencinin programına ait olmalıdır.',422);} $summary=app(\App\Domain\Education::class)->attendanceSummary(Records::get('placements',$r->placement_id));if($req->credited_minutes>$summary['attended_minutes'])throw new DomainError('MAHSUP_SURESI','Mahsup süresi tamamlanan eğitim süresini aşamaz.');$r->credited_minutes=$req->credited_minutes;$r->credited_outcomes=$req->credited_outcomes;$r->credit_reviewed_by=$req->user()->id; }
            if($a->role==='mudur' && $req->filled('directorate_document_id')) { $doc=Records::get('documents',$req->directorate_document_id);Gate::authorize('record-view',$doc);if(!app(Eligibility::class)->cleanDocument($doc->id))throw new DomainError('BELGE_EKSIK','Müdürlük onay belgesi geçersiz.');$r->directorate_document_id=$doc->id; }
            $r->save();return $r->refresh();
        });
    }
    public function onlinePermission(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer']);
        return $this->transaction($req,function($a)use($req,$id){$p=Records::query('placements')->lockForUpdate()->findOrFail($id);app(ScopeAccess::class)->assertAction('mue_komisyon',$p);$this->version($req,$p);$proposal=Records::query('decisions')->where('target_type','placements')->where('target_id',$p->id)->where('stage','bolum')->latest()->first();if(!$proposal || $proposal->outcome!=='onay' || !app(Eligibility::class)->cleanDocument($proposal->document_id))throw new DomainError('BOLUM_ONERISI','Çevrim içi denetim için belgeli Bölüm Komisyonu önerisi gerekir.',422);if($req->input('outcome')!=='onay')throw new DomainError('IZIN_KARARI','Çevrim içi izin için onay kararı gerekir.',422);$d=app(Workflow::class)->decision($p,$req->all(),$a);$p->online_decision_id=$d->id;$p->save();return $p->refresh();});
    }
    public function onlineProposal(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','outcome'=>'required|in:onay,ret']);
        return $this->transaction($req,function($a)use($req,$id){$p=Records::query('placements')->lockForUpdate()->findOrFail($id);app(ScopeAccess::class)->assertAction('bolum_komisyon',$p);$this->version($req,$p);$d=app(Workflow::class)->decision($p,$req->all(),$a);$p->online_decision_id=null;$p->save();$p->increment('version');return $d;});
    }
    public function boardDecision(Request $req,string $resource,string $id)
    {
        $req->validate(['version'=>'required|integer']);
        abort_unless(in_array($resource,['recognition_requests','annual_reports','program_outputs','term_programs','rubric_versions']),422);
        return $this->transaction($req,function($a)use($req,$resource,$id){
            $r=Records::query($resource)->lockForUpdate()->findOrFail($id);app(ScopeAccess::class)->assertAction('mudur',$r);$this->version($req,$r);
            $decision=app(Workflow::class)->decision($r,array_merge($req->all(),['stage'=>'kurul']),$a);
            if($resource==='recognition_requests'){$r->board_decision_id=$decision->id;$r->save();}
            if($resource==='annual_reports'){$r->board_document_id=$decision->outcome==='onay'?$decision->document_id:null;$r->save();}
            $r->increment('version');
            return $decision;
        });
    }
    public function directorateApproval(Request $req,string $resource,string $id)
    {
        abort_unless(in_array($resource,['change_requests','nonconformities']),404);
        $req->validate(['version'=>'required|integer','document_id'=>'required|uuid','reason'=>'required|string']);
        return $this->transaction($req,function()use($req,$resource,$id){$r=Records::query($resource)->lockForUpdate()->findOrFail($id);app(ScopeAccess::class)->assertAction('mudur',$r);$this->version($req,$r);$doc=Records::get('documents',$req->document_id);Gate::authorize('record-view',$doc);if($doc->institution_id!==$r->institution_id || !app(Eligibility::class)->cleanDocument($doc->id))throw new DomainError('BELGE_EKSIK','Müdürlük onay belgesi geçersiz.');$r->directorate_document_id=$doc->id;$r->save();return $r->refresh();});
    }
    public function semesterGuidance(Request $req,string $id)
    {
        $r=Records::get('applications',$id);Gate::authorize('record-view',$r);$tp=Records::query('term_programs')->where('program_id',$r->program_id)->where('term_id',$r->term_id)->first();
        if(!$tp)throw new DomainError('KURUL_PLANI_EKSIK','Dönem/program kurul planı tanımlanmadı.');
        return app(\App\Domain\EducationModel::class)->semester(Records::get('students',$r->student_id),$tp,$req->input('requested_semester')?(int)$req->input('requested_semester'):null);
    }
}
