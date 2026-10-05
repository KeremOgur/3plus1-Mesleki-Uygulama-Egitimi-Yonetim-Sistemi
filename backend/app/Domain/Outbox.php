<?php
namespace App\Domain;
use App\Models\DomainRecord;
class Outbox
{
    public function record(DomainRecord $r,string $event,string $message): void
    {
        $recipients=[];
        if($r->student_id) $recipients[]=Records::get('students',$r->student_id)->user_id;
        foreach(Records::query('role_assignments')->where('institution_id',$r->institution_id)->where('state','aktif')->where('valid_from','<=',now())->where(fn($q)=>$q->whereNull('valid_until')->orWhere('valid_until','>=',now()))->get() as $a) {
            $scope=Records::scope($r);$matches=true;foreach(['program_id','term_id','company_id','student_id'] as $key)if($a->$key && $a->$key!==($scope[$key]??null))$matches=false;
            if($matches && in_array($a->role,['mudur','mue_komisyon','bolum_komisyon','program_baskani','koordinator','isletme_yetkilisi']))$recipients[]=$a->user_id;
        }
        if($r->student_id && $r->term_id)foreach(Records::query('adviser_assignments')->where('student_id',$r->student_id)->where('term_id',$r->term_id)->where('valid_from','<=',today())->where('valid_until','>=',today())->get() as $a)$recipients[]=$a->user_id;
        if($r->placement_id)foreach(Records::query('trainer_assignments')->where('placement_id',$r->placement_id)->where('valid_from','<=',today())->where('valid_until','>=',today())->get() as $a)$recipients[]=Records::get('trainer_qualifications',$a->trainer_id)->user_id;
        Records::create('outbox_events',array_merge(Records::scope($r),['event_type'=>$event,'aggregate_id'=>$r->id,'recipient_ids'=>array_values(array_unique($recipients)),'payload'=>['message'=>$message],'state'=>'bekliyor']));
    }
    public function deliver(): int
    {
        return \Illuminate\Support\Facades\DB::transaction(function(){
            $events=Records::query('outbox_events')->whereNull('processed_at')->orderBy('id')->lockForUpdate()->limit(100)->get();
            foreach($events as $e) {
                foreach($e->recipient_ids as $id) if(!Records::query('notifications')->where('recipient_id',$id)->where('event_key',$e->id)->where('channel','portal')->exists()) Records::create('notifications',array_merge(Records::scope($e),['recipient_id'=>$id,'event_key'=>$e->id,'channel'=>'portal','message'=>$e->payload['message'],'retry_count'=>0,'sent_at'=>now(),'state'=>'gonderildi']));
                $e->processed_at=now(); $e->state='islendi'; $e->save();
            }
            return $events->count();
        });
    }
}
