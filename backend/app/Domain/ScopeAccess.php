<?php
namespace App\Domain;
use App\Models\User;
use App\Models\DomainRecord;

class ScopeAccess
{
    public function assignment(User $u): DomainRecord
    {
        $id=request()->header('X-Assignment-Id');
        if (!$id) throw new DomainError('GOREV_GEREKLI','İşlem için aktif görevinizi seçin.',403);
        if(!\Illuminate\Support\Str::isUuid($id))throw new DomainError('FORBIDDEN_SCOPE','Geçerli görevlendirme bulunamadı.',403);
        $cached=request()->attributes->get('mue.assignment_cache');
        if($u->active && $u->allowedEnvironment() && $cached && $cached->id===$id && $cached->user_id===$u->id)return $cached;
        $a=Records::query('role_assignments')->where('id',$id)->where('user_id',$u->id)->where('state','aktif')
            ->where('valid_from','<=',now())->where(fn($q)=>$q->whereNull('valid_until')->orWhere('valid_until','>=',now()))->first();
        if (!$u->active || !$u->allowedEnvironment() || !$a) throw new DomainError('FORBIDDEN_SCOPE','Geçerli görevlendirme bulunamadı.',403);
        if(app()->environment('production') && in_array($a->role,Mfa::ROLES)) {
            $token=$u->currentAccessToken();$verified=$token && property_exists($token,'exists') && $token->exists?in_array('mfa_verified',$token->abilities??[]):(request()->hasSession() && request()->session()->get('mfa_verified'));
            if(!$u->mfa_confirmed_at || !$verified)throw new DomainError('MFA_GEREKLI','Yetkili görev için çok faktörlü giriş yapın.',403);
        }
        request()->attributes->set('mue.assignment_cache',$a);return $a;
    }

    public function can(User $u, string $ability, DomainRecord $r): bool
    {
        $a=$this->assignment($u); $type=$r->getTable(); $spec=config("domain.$type");
        if (!$spec) return false;
        if (!in_array($a->role, $spec[$ability==='view'?'read':'write'])) return false;
        if ($a->role === 'sistem_yoneticisi') return in_array($type,['institutions','role_assignments','change_control','outbox_events','notifications']);
        if ($type === 'institutions') return $a->institution_id === $r->id;
        if ($a->institution_id !== $r->institution_id) return false;
        if($type==='notifications') return $r->recipient_id===$u->id;
        $scope=Records::scope($r);
        if($type==='financial_policies' && $ability==='view' && in_array($a->role,['belge_gorevlisi','isletme_yetkilisi']))return $a->role==='belge_gorevlisi' || $r->state==='onaylandi';
        if($type==='feedback' && $ability==='view' && in_array($a->role,['ogrenci','akademik_danisman','egitici','isletme_yetkilisi']))return $r->created_by===$u->id;
        $catalogs=['departments','programs','academic_terms','program_outputs','learning_outcomes','rubric_versions','term_programs'];
        if($ability==='view' && in_array($type,$catalogs)) return !$a->program_id || empty($scope['program_id']) || $scope['program_id']===$a->program_id;
        $companyCatalogs=['offers','companies','company_sites','protocols','company_assessments','trainer_qualifications','risk_plans','risk_items'];
        if($type==='risk_items' && $ability==='view')return $this->can($u,'view',Records::get('risk_plans',$r->risk_plan_id));
        if($ability==='view' && $a->role==='ogrenci' && in_array($type,$companyCatalogs)) {
            $student=Records::query('students')->where('user_id',$u->id)->first();
            return $student && app(Eligibility::class)->studentCanViewOfferResource($student,$r);
        }
        if($ability==='view' && in_array($a->role,['akademik_danisman','egitici']) && in_array($type,$companyCatalogs)) {
            $placements=Records::query('placements')->where('institution_id',$a->institution_id);
            if($a->term_id)$placements->where('term_id',$a->term_id);
            foreach(['program_id','company_id','student_id'] as $axis)if($a->$axis)$placements->where($axis,$a->$axis);
            if($a->role==='akademik_danisman')$placements->whereExists(function($q)use($u){$q->selectRaw('1')->from('adviser_assignments')->where('user_id',$u->id)->whereColumn('adviser_assignments.student_id','placements.student_id')->whereColumn('adviser_assignments.term_id','placements.term_id')->where('valid_from','<=',today())->where('valid_until','>=',today());});
            else $placements->whereIn('id',Records::query('trainer_assignments')->whereIn('trainer_id',Records::query('trainer_qualifications')->where('user_id',$u->id)->select('id'))->where('valid_from','<=',today())->where('valid_until','>=',today())->select('placement_id'));
            $field=match($type){'offers'=>'id','companies'=>'company_id','company_sites','risk_plans'=>'site_id','protocols'=>'protocol_id','company_assessments'=>'assessment_id','trainer_qualifications'=>'trainer_id'};
            return Records::query('offers')->whereIn('id',$placements->select('offer_id'))->where($field,$type==='risk_plans'?$r->site_id:$r->id)->exists();
        }
        if($ability==='view' && in_array($a->role,['bolum_komisyon','program_baskani','koordinator']) && in_array($type,['companies','company_sites','protocols','trainer_qualifications','risk_plans']) && empty($scope['program_id'])) return true;
        if($type==='documents') {
            if(in_array($r->classification,['saglik','disiplin']) && !in_array($a->role,['ogrenci','mudur','mue_komisyon','belge_gorevlisi'])) return false;
            if($r->target_type==='documents' || !config('domain.'.$r->target_type)) return false;
            if($a->role==='ogrenci' && in_array($r->target_type,['companies','company_sites','trainer_qualifications','company_assessments','protocols'])) return false;
            $target=Records::query($r->target_type)->find($r->target_id);
            return $target && $this->can($u,'view',$target);
        }
        foreach (['program_id','term_id','company_id','student_id'] as $k) if ($a->$k && ($scope[$k]??null) !== $a->$k) return false;
        if ($a->role==='ogrenci') {
            $student=Records::query('students')->where('user_id',$u->id)->first();
            if (!$student) return false;
            if (in_array($type,['programs','departments','academic_terms','program_outputs','learning_outcomes','term_programs'])) return !$r->program_id || $r->program_id===$student->program_id;
            if (in_array($type,['offers','company_assessments','protocols','protocol_scopes','company_sites','companies','trainer_qualifications'])) return $ability==='view' && app(Eligibility::class)->studentCanViewOfferResource($student,$r);
            if ($type==='notifications') return $r->recipient_id===$u->id;
            return $type==='students' ? $r->user_id===$u->id : $r->student_id===$student->id;
        }
        if (in_array($a->role,['akademik_danisman','egitici'])) {
            if (empty($scope['student_id'])) return false;
            if ($a->role==='akademik_danisman') { $query=Records::query('adviser_assignments')->where('user_id',$u->id)->where('student_id',$scope['student_id'])->where('valid_from','<=',today())->where('valid_until','>=',today()); if($r->term_id)$query->where('term_id',$r->term_id);return $query->exists(); }
            return Records::query('trainer_assignments')->where('placement_id',$scope['placement_id']??null)->where('valid_from','<=',today())->where('valid_until','>=',today())->whereIn('trainer_id',Records::query('trainer_qualifications')->where('user_id',$u->id)->select('id'))->exists();
        }
        if ($a->role==='isletme_yetkilisi') {
            if (!$a->company_id || ($scope['company_id']??null)!==$a->company_id) return false;
            if (in_array($type,['students','applications','insurance_records','success_results','self_assessments'])) return false;
            if ($r->student_id) return Records::query('placements')->where('student_id',$r->student_id)->where('company_id',$a->company_id)->where('term_id',$r->term_id)->exists()
                || ($type==='interviews' && Records::query('preferences')->where('application_id',$r->application_id)->where('revision',Records::query('preferences')->where('application_id',$r->application_id)->whereNotNull('submitted_at')->max('revision'))->where('offer_id',$r->offer_id)->whereNotNull('submitted_at')->exists());
        }
        if ($a->role==='belge_gorevlisi') return $type==='financial_policies' || ($r->student_id!==null && ($a->student_id===$r->student_id || ($a->program_id && $a->program_id===$r->program_id)));
        if (in_array($a->role,['bolum_komisyon','program_baskani']) && !$a->program_id) return false;
        return true;
    }

    public function assertAction(string $roleList, DomainRecord $r): DomainRecord
    {
        $a=$this->assignment(auth()->user());
        if (!in_array($a->role,explode(' ',$roleList)) || $a->institution_id!==$r->institution_id) throw new DomainError('FORBIDDEN_SCOPE','Bu karar veya işlem için yetkiniz yok.',403);
        $scope=Records::scope($r);
        foreach (['program_id','term_id','company_id','student_id'] as $k) {
            if($k==='program_id' && empty($scope[$k]) && $r->getTable()==='trainer_qualifications' && $a->role==='bolum_komisyon')continue;
            if($a->$k && $a->$k!==($scope[$k]??null)) throw new DomainError('FORBIDDEN_SCOPE','Görev kapsamı dışındaki kayda erişilemez.',403);
        }
        if (in_array($a->role,['bolum_komisyon','program_baskani']) && !$a->program_id) throw new DomainError('FORBIDDEN_SCOPE','Program görevlendirmesi gereklidir.',403);
        return $a;
    }
}
