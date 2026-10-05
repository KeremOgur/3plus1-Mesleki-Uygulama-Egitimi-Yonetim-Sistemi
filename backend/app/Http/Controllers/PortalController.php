<?php
namespace App\Http\Controllers;
use App\Domain\{Records,ScopeAccess,Workflow};
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class PortalController extends Controller
{
    public function page(Request $r,string $page='dashboard',?string $id=null)
    {
        $a=$r->attributes->get('portal_assignment');
        if(config("domain.$page"))abort_unless(in_array($a->role,array_unique(array_merge(config("domain.$page.read"),config("domain.$page.write")))),403);
        else abort_unless(in_array($page,['dashboard','preferences','matching','reports','accounts','imports','ek']),404);
        if(in_array($page,['matching','imports']) && !in_array($a->role,['mudur','mue_komisyon','bolum_komisyon','program_baskani']))abort(403);
        if($page==='accounts' && $a->role!=='sistem_yoneticisi')abort(403);
        if($page==='reports' && !in_array($a->role,['mudur','mue_komisyon','bolum_komisyon','program_baskani','koordinator','akademik_danisman']))abort(403);
        if($id && (!config("domain.$page") || !in_array($a->role,config("domain.$page.read"))))abort(404);
        $record=null;if($id){$record=Records::get($page,$id);Gate::authorize('record-view',$record);abort_unless(app(ResourceController::class)->visible($a->role,$record),404);$record=app(ResourceController::class)->present($a->role,$record);}
        $resources=[];foreach(config('domain') as $name=>$spec)if(in_array($a->role,array_unique(array_merge($spec['read'],$spec['write'])))) {
            $spec['readable']=in_array($a->role,$spec['read']);
            $spec['can_upload']=$name==='documents' && in_array($a->role,$spec['write']);
            $spec['writable']=in_array($a->role,$spec['write'])&&!in_array($name,ResourceController::INTERNAL);
            $spec['actions']=[];foreach($spec['states'] as $state=>$targets)foreach($targets as $target){$rule=app(Workflow::class)->rulesFor($name,$target);if(in_array($a->role,$rule['roles']))$spec['actions'][$state][$target]=['decision'=>$rule['decision']];}
            $resources[$name]=$spec;
        }
        $boot=['page'=>$page,'record'=>$record,'assignment'=>$a,'user'=>$r->user()->only('id','name','email'),'resources'=>$resources,'ui'=>config('portal'),'forms'=>config('forms'),'demo'=>app()->environment('local') && str_starts_with($r->user()->external_subject??'','mue-demo:')];
        return view('portal.page',['boot'=>$boot,'assignment'=>$a,'page'=>$page,'resources'=>$resources]);
    }
    public function users(Request $r)
    {
        $a=$r->attributes->get('portal_assignment');$q=User::query();
        if(in_array($a->role,['ogrenci','akademik_danisman','egitici']))return ['items'=>[$r->user()->only('id','name','email','active')]];
        if($a->role!=='sistem_yoneticisi') {
            $q->where('active',true);
            $ids=Records::query('role_assignments')->where('institution_id',$a->institution_id);
            if($a->company_id)$ids->where('company_id',$a->company_id);
            if($a->program_id)$ids->where('program_id',$a->program_id);
            $q->whereIn('id',$ids->select('user_id'));
            // An exact identity lookup lets the Director assign a newly created account.
            if($a->role==='mudur' && $r->filled('email'))$q=User::where('active',true)->where('email',$r->email);
        }
        if($r->filled('q'))$q->where('name','ilike','%'.mb_substr($r->q,0,80).'%');
        return ['items'=>$q->orderBy('name')->limit(100)->get(['id','name','email','active'])];
    }
    public function interviewCandidates(Request $r)
    {
        $a=$r->attributes->get('portal_assignment');abort_unless($a->role==='isletme_yetkilisi' && $a->company_id,403);
        $offers=Records::query('offers')->where('institution_id',$a->institution_id)->where('company_id',$a->company_id)->where('state','ilan_edildi');if($a->term_id)$offers->where('term_id',$a->term_id);
        $preferences=Records::query('preferences')->whereIn('offer_id',$offers->select('id'))->whereNotNull('submitted_at')->orderBy('application_id')->get();$items=[];
        foreach($preferences as $p) { $latest=Records::query('preferences')->where('application_id',$p->application_id)->whereNotNull('submitted_at')->max('revision');if($p->revision!==$latest)continue;$s=Records::get('students',$p->student_id);$items[]=['id'=>$p->application_id,'name'=>User::findOrFail($s->user_id)->name,'student_no'=>$s->student_no,'offer_id'=>$p->offer_id,'program'=>Records::get('programs',$s->program_id)->name]; }
        return ['items'=>$items];
    }
    public function studentSummary(Request $r,string $id)
    {
        $p=Records::get('placements',$id);Gate::authorize('record-view',$p);$s=Records::get('students',$p->student_id);
        return ['student_no'=>$s->student_no,'name'=>User::findOrFail($s->user_id)->name,'program'=>Records::get('programs',$s->program_id)->name];
    }
    public function markRead(Request $r,string $id)
    {
        $n=Records::get('notifications',$id);Gate::authorize('record-view',$n);abort_unless($n->recipient_id===$r->user()->id,403);
        return app(ResourceController::class)->transaction($r,function()use($n){$n->read_at=now();$n->save();return ['message'=>'Bildirim okundu olarak işaretlendi.'];});
    }
    public function preferencePolicy(Request $r,string $id)
    {
        abort_unless($r->attributes->get('portal_assignment')->role==='ogrenci',403);
        $application=Records::get('applications',$id);Gate::authorize('record-view',$application);
        $policy=Records::query('matching_policies')->where('term_id',$application->term_id)->where('program_id',$application->program_id)->where('state','onaylandi')->latest()->first();
        $term=Records::get('academic_terms',$application->term_id);
        $programCalendar=Records::query('term_programs')->where('program_id',$application->program_id)->where('term_id',$application->term_id)->first();
        return ['policy'=>$policy?->only('id','revision','preference_weight','transport_weight','max_preferences','transport_rules'),
            'calendar'=>['state'=>$term->state,'preference_opens_at'=>$programCalendar?->preference_opens_at??$term->preference_opens_at,'preference_deadline'=>$programCalendar?->preference_deadline??$term->preference_deadline]];
    }
    public function overview(Request $r)
    {
        $page=app(ResourceController::class)->index($r->merge(['limit'=>20]),'placements');$items=[];
        foreach($page['items'] as $row){$p=Records::get('placements',$row['id']);$t=Records::get('academic_terms',$p->term_id);$s=Records::get('students',$p->student_id);$items[]=['id'=>$p->id,'student_name'=>User::findOrFail($s->user_id)->name,'student_no'=>$s->student_no,'state'=>$p->state,'starts_on'=>$p->starts_on,'ends_on'=>$p->ends_on,'attendance'=>app(\App\Domain\Education::class)->attendanceSummary($p),'monitoring'=>app(\App\Domain\Education::class)->monitoring($p),'readiness'=>app(\App\Domain\Eligibility::class)->ready($p),'plan_deadline'=>app(\App\Domain\BusinessCalendar::class)->deadline($p->starts_on,10,$t)->toISOString(),'file_deadline'=>app(\App\Domain\BusinessCalendar::class)->deadline($p->ends_on,10,$t)->toISOString()];}
        return array_merge($page,['items'=>$items]);
    }
}
