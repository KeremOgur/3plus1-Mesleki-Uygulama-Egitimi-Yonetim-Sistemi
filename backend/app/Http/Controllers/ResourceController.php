<?php
namespace App\Http\Controllers;
use App\Domain\{Records,InputRules,ScopeAccess,Workflow,DomainError};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};

class ResourceController extends Controller
{
    public const INTERNAL=['preferences','matching_runs','candidate_scores','match_results','decisions','publications','publication_items','success_results','documents','import_batches','notifications','outbox_events'];
    public function index(Request $request,string $resource)
    {
        $spec=config("domain.$resource"); abort_unless($spec,404,'Kayıt türü bulunamadı.');
        $filters=['cursor'=>'nullable|uuid','limit'=>'nullable|integer|min:1|max:100','q'=>'nullable|string','state'=>'nullable|string','form_code'=>'nullable|string'];
        foreach(['institution_id','program_id','term_id','company_id','student_id','placement_id'] as $field)$filters[$field]='nullable|uuid';
        foreach($spec['fields'] as $field=>$definition)if($definition['ref'])$filters[$field]=$definition['ref']==='users'?'nullable|integer|min:1':'nullable|uuid';
        $request->validate($filters);
        $access=app(ScopeAccess::class); $a=$access->assignment($request->user());
        $q=Records::query($resource);
        if($request->filled('state')) $q->where('state',$request->input('state'));
        if($resource==='declarations' && $request->filled('form_code'))$q->where('form_code',$request->input('form_code'));
        if($request->filled('q')) {
            $search=mb_substr((string)$request->input('q'),0,100);
            $columns=array_intersect(['code','name','legal_name','student_no','number','description','form_code','activity_text','request_no'],array_keys($spec['fields']));
            if($columns)$q->where(function($query)use($columns,$search){foreach($columns as $column)$query->orWhere($column,'ilike','%'.$search.'%');});
        }
        if($resource!=='institutions') $q->where('institution_id',$a->institution_id);
        foreach(['program_id','term_id','company_id','student_id','placement_id'] as $field) if($request->filled($field)) $q->where($field,$request->input($field));
        foreach($spec['fields'] as $field=>$definition)if($definition['ref'] && $request->filled($field))$q->where($field,$request->input($field));
        if($a->program_id && $resource==='programs') $q->where('id',$a->program_id);
        elseif($a->program_id && !in_array($resource,['institutions','departments','academic_terms','companies','company_sites','protocols','trainer_qualifications'])) $q->where('program_id',$a->program_id);
        if($a->company_id && $resource==='companies')$q->where('id',$a->company_id);
        elseif($a->company_id && !in_array($resource,['institutions','departments','programs','academic_terms','program_outputs','learning_outcomes','rubric_versions','term_programs','financial_policies'])) $q->where('company_id',$a->company_id);
        $items=[]; $limit=min(100,max(1,(int)$request->input('limit',25))); $cursor=$request->input('cursor');
        // For these records the MYO policy is exactly institution + optional program;
        // paginate/count in SQL instead of materializing every authorized record.
        $simple=['students','applications','preferences','placements','weekly_reports','attendance','rubric_evaluations','success_results','company_performance','annual_reports','improvement_actions'];
        if(in_array($a->role,['mudur','mue_komisyon','bolum_komisyon','program_baskani','koordinator']) && in_array($a->role,$spec['read']) && in_array($resource,$simple) && !$a->term_id && !$a->company_id && !$a->student_id && (!in_array($a->role,['bolum_komisyon','program_baskani']) || $a->program_id)) {
            $total=(clone $q)->count();if($cursor)$q->where('id','>',$cursor);$rows=$q->orderBy('id')->limit($limit+1)->get();$more=$rows->count()>$limit;
            foreach($rows->take($limit) as $r){Gate::authorize('record-view',$r);$items[]=$this->present($a->role,$r);}return ['items'=>$items,'next_cursor'=>$more?end($items)['id']:null,'total'=>$total];
        }
        $authorized=[];
        // Policy applies before pagination, counts and export to avoid data/count disclosure.
        foreach($q->orderBy('id')->cursor() as $r) if($access->can($request->user(),'view',$r) && $this->visible($a->role,$r)) $authorized[]=$r;
        $total=count($authorized); $after=array_values(array_filter($authorized,fn($r)=>!$cursor || strcmp($r->id,$cursor)>0));
        foreach(array_slice($after,0,$limit) as $r) $items[]=$this->present($a->role,$r);
        return ['items'=>$items,'next_cursor'=>count($after)>$limit?end($items)['id']:null,'total'=>$total];
    }
    public function show(Request $request,string $resource,string $id)
    {
        $r=Records::get($resource,$id); Gate::authorize('record-view',$r); $a=app(ScopeAccess::class)->assignment($request->user());
        abort_unless($this->visible($a->role,$r),404,'Kayıt henüz ilan edilmedi.');
        return $this->present($a->role,$r);
    }
    public function store(Request $request,string $resource)
    {
        if(in_array($resource,array_merge(self::INTERNAL,['appeals']))) throw new DomainError('OZEL_SUREC','Bu kayıt için ilgili süreç uç noktasını kullanın.',422);
        return $this->transaction($request,function($a)use($request,$resource){
            $data=app(InputRules::class)->validate($resource,$request->all(),$a);
            if($resource==='placements') { $check=app(\App\Domain\Eligibility::class)->pair(Records::get('applications',$data['application_id']),Records::get('offers',$data['offer_id'])); if(!$check['eligible']) throw new DomainError('YERLESTIRME_UYGUN_DEGIL',implode(' ',$check['reasons'])); }
            if($states=config("domain.$resource.states"))$data['state']=array_key_first($states);
            if(in_array($resource,['role_assignments','adviser_assignments','trainer_assignments'])) $data['state']='aktif';
            if(in_array($resource,['plan_outcomes','weekly_plan_tasks','report_outcomes','declarations'])) $data['state']='kayitli';
            if($resource==='feedback')$data['state']='gonderildi';
            $record=Records::create($resource,$data);
            if(in_array($resource,['incidents','nonconformities','appeals','change_requests','weekly_reports','capacity_requests']))app(\App\Domain\Outbox::class)->record($record,'yeni_kayit','Yeni süreç kaydı oluşturuldu: '.(config("domain.$resource.ek")?'EK-'.config("domain.$resource.ek"):$resource));
            return response()->json($this->present($a->role,$record),201);
        });
    }
    public function update(Request $request,string $resource,string $id)
    {
        if(in_array($resource,self::INTERNAL)) throw new DomainError('OZEL_SUREC','Bu kayıt doğrudan düzenlenemez.',422);
        return $this->transaction($request,function($a)use($request,$resource,$id){
            $r=Records::query($resource)->lockForUpdate()->findOrFail($id); Gate::authorize('record-write',$r); $this->version($request,$r);
            $catalog=!config("domain.$resource.states") || ($resource==='academic_terms' && $r->state==='acik') || ($resource==='companies' && $r->state==='aktif');
            $interview=$resource==='interviews' && in_array($r->state,['gonderildi','inceleniyor']);
            $action=$resource==='improvement_actions' && in_array($r->state,['acik','uygulaniyor','kontrol_edildi']);
            if(!$catalog && !$interview && !$action && !in_array($r->state,['taslak','iade','eksik','talep'])) throw new DomainError('KAYIT_KILITLI','Bu kayıt kilitli; gerekçeli yeni sürüm oluşturun.');
            if(($catalog || $interview || $action) && !$request->filled('reason')) throw new DomainError('GEREKCE_GEREKLI','Kayıt değişikliği için gerekçe zorunludur.',422);
            $data=app(InputRules::class)->validate($resource,array_merge($r->toArray(),$request->all()),$a,$r);
            $r->fill($data)->save(); return $this->present($a->role,$r->refresh());
        });
    }
    public function transition(Request $request,string $resource,string $id)
    {
        $request->validate(['state'=>'required|string','version'=>'required|integer|min:1']);
        return $this->transaction($request,function($a)use($request,$resource,$id){
            $r=Records::query($resource)->lockForUpdate()->findOrFail($id); $this->version($request,$r);
            return $this->present($a->role,app(Workflow::class)->transition($r,$request->input('state'),$request->all()));
        });
    }
    public function history(Request $request,string $resource,string $id)
    {
        $r=Records::get($resource,$id); Gate::authorize('record-view',$r); $a=app(ScopeAccess::class)->assignment($request->user());
        abort_unless($this->visible($a->role,$r),404);
        return DB::table('record_revisions')->where('record_type',$resource)->where('record_id',$id)->orderBy('version')->get()->map(function($x)use($resource,$a){
            $historical=\App\Models\DomainRecord::for($resource);$attributes=json_decode($x->snapshot,true,512,JSON_THROW_ON_ERROR);
            // PostgreSQL to_jsonb embeds JSON columns as arrays/objects. Eloquent's
            // raw attributes must contain JSON strings, while encrypted fields
            // retain their stored ciphertext for normal hydration and redaction.
            foreach(config("domain.$resource.fields") as $key=>$field)if($field['type']==='jsonb' && isset($attributes[$key]))$attributes[$key]=json_encode($attributes[$key],JSON_THROW_ON_ERROR);
            $historical->setRawAttributes($attributes,true);
            return ['version'=>$x->version,'recorded_at'=>$x->recorded_at,'record'=>$this->present($a->role,$historical)];
        });
    }
    public function transaction(Request $request,callable $fn)
    {
        return DB::transaction(function()use($request,$fn){
            $a=app(ScopeAccess::class)->assignment($request->user()); Records::auditContext($request->user()->id,$a->id,$request->input('reason')); return $fn($a);
        },3);
    }
    public function version(Request $request,$r): void
    { if((int)$request->input('version')!==$r->version) throw new DomainError('STALE_VERSION','Kayıt değişti; güncel sürümü inceleyin.'); }
    public function visible(string $role,$r): bool
    { return !in_array($role,['ogrenci','isletme_yetkilisi','egitici','akademik_danisman']) || !in_array($r->getTable(),['placements','success_results']) || in_array($r->state,['ilan_edildi','baslamaya_hazir','basladi','tamamlandi','askida','degistirildi']); }
    public function present(string $role,$r): array
    {
        $data=$r->toArray();
        if(in_array($role,['ogrenci','akademik_danisman','egitici']) && $r->getTable()==='companies')foreach(['iban','tax_no','mersis_no','sgk_no','kep'] as $key)unset($data[$key]);
        if(in_array($role,['ogrenci','akademik_danisman']) && $r->getTable()==='trainer_qualifications')unset($data['national_id']);
        if(in_array($role,['isletme_yetkilisi','egitici'])) foreach(['national_id','gpa','gpa_snapshot','academic_data','course_conditions','eligibility_reason','iban','academic_source','academic_fetched_at','gpa_source','gpa_recorded_at'] as $k) unset($data[$k]);
        if($r->getTable()==='documents') foreach(['object_key','target_id','supersedes_id'] as $k) unset($data[$k]);
        if($r->getTable()==='matching_runs' && !in_array($role,['mudur','mue_komisyon','bolum_komisyon'])) unset($data['snapshot'],$data['result']);
        return $data;
    }
}
