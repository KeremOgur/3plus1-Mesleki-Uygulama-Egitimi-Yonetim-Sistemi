<?php
namespace App\Http\Controllers;
use App\Domain\{Records,ScopeAccess,DomainError,Eligibility,Matching,Education,Workflow,BusinessCalendar};
use App\Jobs\RunMatching;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate,DB};

class ProcessController extends ResourceController
{
    public function preferences(Request $req,string $id,bool $administrative=false)
    {
        $req->validate(['version'=>'required|integer','preferences'=>'required|array|min:1','preferences.*.offer_id'=>'required|uuid|distinct','preferences.*.reachable'=>'required|boolean','preferences.*.one_way_minutes'=>'required|numeric|min:0']);
        if($administrative)$req->validate(['reason'=>'required|string|max:20000']);
        return $this->transaction($req,function($a)use($req,$id,$administrative){
            $app=Records::query('applications')->lockForUpdate()->findOrFail($id);
            if($administrative)app(ScopeAccess::class)->assertAction('bolum_komisyon',$app);else{Gate::authorize('record-write',$app);$this->preferenceDeadline($app);}
            $this->version($req,$app);
            if($app->state!=='uygun') throw new DomainError('BASVURU_UYGUN_DEGIL','Tercih için uygun başvuru gerekir.');
            $policy=Records::query('matching_policies')->where('term_id',$app->term_id)->where('program_id',$app->program_id)->where('state','onaylandi')->latest()->first();
            if(!$policy) throw new DomainError('POLICY_NOT_APPROVED','Onaylı tercih politikası yok.');
            if(count($req->preferences)>$policy->max_preferences) throw new DomainError('TERCIH_SINIRI','Azami tercih sayısı aşıldı.',422);
            $rev=(Records::query('preferences')->where('application_id',$id)->max('revision')??0)+1; $out=[];
            foreach($req->preferences as $rank=>$p) {
                $offer=Records::get('offers',$p['offer_id']); Gate::authorize('record-view',$offer);
                if($offer->state!=='ilan_edildi' || $offer->program_id!==$app->program_id || $offer->term_id!==$app->term_id || app(Eligibility::class)->offerReasons($offer)) throw new DomainError('TEKLIF_UYGUN_DEGIL','Tercih listesindeki teklif uygun değil.',422);
                $out[]=Records::create('preferences',array_merge(Records::scope($app),array_intersect_key($p,array_flip(['offer_id','reachable','one_way_minutes'])),['company_id'=>$offer->company_id,'application_id'=>$id,'rank'=>$rank+1,'revision'=>$rev,'transport_verified'=>$administrative,'submitted_at'=>$administrative?now():null,'state'=>$administrative?'kesinlesti':'taslak']));
            }
            $app->increment('version'); return ['revision'=>$rev,'preferences'=>$out,'application_version'=>$app->refresh()->version];
        });
    }
    public function correctPreferences(Request $req,string $id) { return $this->preferences($req,$id,true); }
    private function preferenceDeadline($app): void
    {
        $term=Records::get('academic_terms',$app->term_id); $tp=Records::query('term_programs')->where('program_id',$app->program_id)->where('term_id',$app->term_id)->first();
        if(now()->lessThan($tp?->preference_opens_at??$term->preference_opens_at) || now()->greaterThan($tp?->preference_deadline??$term->preference_deadline) || $term->state!=='acik') throw new DomainError('DEADLINE_PASSED','Tercih dönemi açık değil veya tercih süresi sona erdi.');
    }
    public function submitPreferences(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','revision'=>'required|integer|min:1']);
        return $this->transaction($req,function()use($req,$id){
            $app=Records::query('applications')->lockForUpdate()->findOrFail($id); Gate::authorize('record-write',$app); $this->version($req,$app); $this->preferenceDeadline($app);
            if($app->state!=='uygun' || $app->eligibility!=='uygun')throw new DomainError('BASVURU_UYGUN_DEGIL','Kesinleştirme için uygun başvuru gerekir.');
            $latest=Records::query('preferences')->where('application_id',$id)->max('revision');
            if($latest!==(int)$req->revision) throw new DomainError('STALE_VERSION','En güncel tercih sürümünü kesinleştirin.');
            $prefs=Records::query('preferences')->where('application_id',$id)->where('revision',$latest)->orderBy('rank')->get();
            if($prefs->isEmpty()) throw new DomainError('TERCIH_EKSIK','Tercih listesi boş.',422);
            foreach($prefs as $i=>$p) {
                if($p->rank!==$i+1 || app(Eligibility::class)->offerReasons(Records::get('offers',$p->offer_id))) throw new DomainError('TERCIH_GECERSIZ','Tercih sıraları veya teklifler geçersiz.',422);
                if(!$p->submitted_at) { $p->submitted_at=now(); $p->state='kesinlesti'; $p->save(); }
            }
            $app->increment('version'); return ['message'=>'Tercihler kesinleştirildi.','revision'=>$latest,'application_version'=>$app->refresh()->version];
        });
    }
    public function verifyTransport(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','revision'=>'required|integer','reason'=>'required|string']);
        return $this->transaction($req,function()use($req,$id){
            $app=Records::query('applications')->lockForUpdate()->findOrFail($id); app(ScopeAccess::class)->assertAction('bolum_komisyon',$app); $this->version($req,$app);
            if(!Records::query('preferences')->where('application_id',$id)->where('revision',$req->revision)->exists())throw new DomainError('TERCIH_EKSIK','Doğrulanacak tercih sürümü bulunamadı.',422);
            foreach(Records::query('preferences')->where('application_id',$id)->where('revision',$req->revision)->get() as $p) { $p->transport_verified=true; $p->save(); }
            $app->increment('version'); return ['message'=>'Ulaşım beyanları doğrulandı.','application_version'=>$app->refresh()->version];
        });
    }
    public function run(Request $req)
    {
        $req->validate(['policy_id'=>'required|uuid']);
        return $this->transaction($req,function()use($req){
            $policy=Records::query('matching_policies')->lockForUpdate()->findOrFail($req->policy_id); app(ScopeAccess::class)->assertAction('bolum_komisyon',$policy);
            $term=Records::get('academic_terms',$policy->term_id);$tp=Records::query('term_programs')->where('term_id',$policy->term_id)->where('program_id',$policy->program_id)->first();if(now()->lessThan($tp?->preference_deadline??$term->preference_deadline)) throw new DomainError('TERCIH_ACIK','Tercih süresi kapanmadan eşleştirme başlatılamaz.');
            $snapshot=app(Matching::class)->snapshot($policy);
            $run=Records::create('matching_runs',array_merge(Records::scope($policy),['policy_id'=>$policy->id,'snapshot'=>$snapshot,'snapshot_hash'=>app(Matching::class)->hash($snapshot),'algorithm_version'=>$snapshot['algorithm_version'],'state'=>'kuyrukta']));
            RunMatching::dispatch($run->id)->afterCommit(); return response()->json(['run_id'=>$run->id,'state'=>'kuyrukta'],202);
        });
    }
    public function recommendations(Request $req,string $id)
    {
        return $this->transaction($req,function()use($id){
            $run=Records::query('matching_runs')->lockForUpdate()->findOrFail($id); app(ScopeAccess::class)->assertAction('bolum_komisyon',$run); app(Matching::class)->assertFresh($run);
            if($run->state!=='tamamlandi') throw new DomainError('RUN_FAILED','Eşleştirme çalışması tamamlanmadı.');
            $out=[];
            foreach(Records::query('match_results')->where('run_id',$run->id)->whereNotNull('offer_id')->get() as $result) {
                $exists=Records::query('placements')->where('run_id',$id)->where('application_id',$result->application_id)->first(); if($exists) { $out[]=$exists; continue; }
                $offer=Records::get('offers',$result->offer_id); $term=Records::get('academic_terms',$run->term_id);
                $out[]=Records::create('placements',array_merge(Records::scope($result),['company_id'=>$offer->company_id,'application_id'=>$result->application_id,'offer_id'=>$offer->id,'run_id'=>$id,'starts_on'=>$term->starts_on,'ends_on'=>$term->ends_on,'credited_minutes'=>0,'credited_outcomes'=>[],'state'=>'onerildi']));
            }
            return ['message'=>'Komisyon incelemesi için öneriler oluşturuldu.','placements'=>$out];
        });
    }
    public function review(Request $req,string $id)
    {
        $req->validate(['version'=>'required|integer','offer_id'=>'required|uuid','reason'=>'required|string']);
        return $this->transaction($req,function()use($req,$id){
            $p=Records::query('placements')->lockForUpdate()->findOrFail($id); app(ScopeAccess::class)->assertAction('bolum_komisyon',$p); $this->version($req,$p);
            if(!in_array($p->state,['onerildi','bolum_incelemesi'])) throw new DomainError('KAYIT_KILITLI','Kesinleşen yerleştirme için değişiklik sürecini kullanın.');
            $offer=Records::get('offers',$req->offer_id); $pair=app(Eligibility::class)->pair(Records::get('applications',$p->application_id),$offer,true);
            if(!$pair['eligible']) throw new DomainError('YERLESTIRME_UYGUN_DEGIL',implode(' ',$pair['reasons']));
            $p->fill(['offer_id'=>$offer->id,'company_id'=>$offer->company_id,'reason'=>$req->reason,'state'=>'bolum_incelemesi'])->save(); return $p->refresh();
        });
    }
    public function publish(Request $req)
    {
        $req->validate(['term_id'=>'required|uuid','targets'=>'required|array|min:1','targets.*.type'=>'required|in:placements,success_results','targets.*.id'=>'required|uuid','decision_document_id'=>'required|uuid']);
        return $this->transaction($req,function()use($req){
            $term=Records::query('academic_terms')->lockForUpdate()->findOrFail($req->term_id); app(ScopeAccess::class)->assertAction('mudur',$term);
            $doc=Records::get('documents',$req->decision_document_id); Gate::authorize('record-view',$doc); if(!app(Eligibility::class)->cleanDocument($doc->id)) throw new DomainError('ILAN_BELGESI','İlan karar belgesi temiz taranmış olmalıdır.');
            $revision=(Records::query('publications')->where('term_id',$term->id)->max('revision')??0)+1;
            $publication=Records::create('publications',array_merge(Records::scope($term),['term_id'=>$term->id,'revision'=>$revision,'decision_document_id'=>$doc->id,'published_at'=>now(),'state'=>'ilan_edildi']));
            foreach($req->targets as $target) {
                $r=Records::query($target['type'])->lockForUpdate()->findOrFail($target['id']); Gate::authorize('record-view',$r);
                if($r->term_id!==$term->id || $r->state!=='onaylandi') throw new DomainError('ILAN_UYGUN_DEGIL','İlan edilen kayıtlar aynı dönemde ve onaylı olmalıdır.');
                if($target['type']==='placements') {
                    if(!Records::query('decisions')->where('target_type','placements')->where('target_id',$r->id)->where('stage','mue')->where('outcome','onay')->exists()) throw new DomainError('KARAR_EKSIK','Nihai MUE Komisyonu kararı olmadan ilan yapılamaz.');
                    $why=app(Eligibility::class)->pair(Records::get('applications',$r->application_id),Records::get('offers',$r->offer_id))['reasons']; if($why) throw new DomainError('ILAN_UYGUN_DEGIL',implode(' ',$why));
                }
                $r->state='ilan_edildi'; $r->save(); $r->refresh();
                Records::create('publication_items',array_merge(Records::scope($r),['publication_id'=>$publication->id,'target_type'=>$target['type'],'target_id'=>$r->id,'target_version'=>$r->version,'content'=>$r->toArray(),'state'=>'ilan_edildi']));
                app(\App\Domain\Outbox::class)->record($r,'ilan','Sonucunuz ilan edildi.');
            }
            return response()->json($publication,201);
        });
    }
    public function appeal(Request $req)
    {
        $req->validate(['target_type'=>'required|in:placements,success_results','target_id'=>'required|uuid','reason'=>'required|string']);
        return $this->transaction($req,function()use($req){
            $target=Records::query($req->target_type)->lockForUpdate()->findOrFail($req->target_id); Gate::authorize('record-view',$target); app(ScopeAccess::class)->assertAction('ogrenci',$target);
            if(!$this->visible('ogrenci',$target)) throw new DomainError('ILAN_EKSIK','İtiraz için sonuç ilan edilmiş olmalıdır.');
            $term=Records::get('academic_terms',$target->term_id);
            if($target->getTable()==='success_results') {
                $item=Records::query('publication_items')->where('target_id',$target->id)->where('target_type','success_results')->latest()->first();
                if(!$item) throw new DomainError('ILAN_EKSIK','Değerlendirme sonucu ilan edilmedi.');
                $publication=Records::get('publications',$item->publication_id); $deadline=app(BusinessCalendar::class)->deadline($publication->published_at,5,$term);
            } else { if(!$term->placement_appeal_deadline) throw new DomainError('ITIRAZ_TAKVIMI','Yerleştirme itiraz takvimi tanımlanmadı.'); $deadline=\Carbon\Carbon::parse($term->placement_appeal_deadline); }
            if(now()->greaterThan($deadline)) throw new DomainError('DEADLINE_PASSED','İtiraz süresi sona erdi.');
            $d=Records::scope($target); $d['target_type']=$req->target_type; $d['target_id']=$target->id; $d['reason']=$req->reason;
            if($req->filled('document_id')) { $doc=Records::get('documents',$req->document_id); Gate::authorize('record-view',$doc); $d['document_id']=$doc->id; }
            $appeal=Records::create('appeals',array_merge($d,['state'=>'alindi']));
            $target->legal_hold=true; $target->save();
            foreach(Records::query('documents')->where('target_type',$req->target_type)->where('target_id',$target->id)->get() as $doc){$doc->legal_hold=true;$doc->save();}
            app(\App\Domain\Outbox::class)->record($appeal,'itiraz_alindi','Sonuca itiraz başvurusu alındı.');return response()->json($appeal,201);
        });
    }
    public function education(Request $req,string $id,string $action)
    {
        $p=Records::get('placements',$id); Gate::authorize('record-view',$p);
        return match($action) {
            'devam'=>app(Education::class)->attendanceSummary($p),
            'denetim'=>app(Education::class)->monitoring($p),
            'baslama'=>['missing'=>app(Eligibility::class)->ready($p)],
            'basari'=>$this->transaction($req,function()use($p){app(ScopeAccess::class)->assertAction('akademik_danisman bolum_komisyon',$p);return app(Education::class)->calculate($p);}),
            default=>abort(404,'İşlem bulunamadı.'),
        };
    }
}
