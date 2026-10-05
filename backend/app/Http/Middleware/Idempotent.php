<?php
namespace App\Http\Middleware;
use App\Domain\DomainError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class Idempotent
{
    public function handle(Request $r,\Closure $next)
    {
        if(!$r->isMethod('POST') && !$r->isMethod('PUT') && !$r->isMethod('PATCH')) return $next($r);
        $key=$r->header('Idempotency-Key');
        if(!$key || strlen($key)>150) throw new DomainError('IDEMPOTENCY_KEY_GEREKLI','Tekrarlanan işlemleri önlemek için Idempotency-Key başlığı gereklidir.',422);
        return DB::transaction(function()use($r,$next,$key){
            $operation=$r->method().':'.$r->path().':'.$r->header('X-Assignment-Id');
            $content=$r->except(array_keys($r->allFiles()));
            foreach($r->allFiles() as $k=>$file) if($file instanceof \Illuminate\Http\UploadedFile)$content[$k]=['sha256'=>hash_file('sha256',$file->getRealPath()),'name'=>$file->getClientOriginalName(),'size'=>$file->getSize()];
            $hash=hash('sha256',json_encode($content,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            DB::table('idempotency_keys')->insertOrIgnore(['user_id'=>$r->user()->id,'operation'=>$operation,'key'=>$key,'request_hash'=>$hash,'created_at'=>now(),'updated_at'=>now()]);
            $record=DB::table('idempotency_keys')->where('user_id',$r->user()->id)->where('operation',$operation)->where('key',$key)->lockForUpdate()->first();
            if($record->request_hash!==$hash) throw new DomainError('IDEMPOTENCY_CONFLICT','Aynı işlem anahtarı farklı içerikle kullanılamaz.');
            // Re-authorize current assignment on replay (revoked duties never inherit access).
            app(\App\Domain\ScopeAccess::class)->assignment($r->user());
            if($record->response) {
                $type=$r->route('resource') ?? match(explode('/',$r->path())[2]??'') {'applications'=>'applications','placements'=>'placements','matching-runs'=>'matching_runs','imports'=>'import_batches',default=>null};
                if($type && $id=$r->route('id')) \Illuminate\Support\Facades\Gate::authorize('record-view',\App\Domain\Records::get($type,$id));
                return response()->json(json_decode($record->response,true),$record->http_status)->header('Idempotent-Replayed','true');
            }
            $result=$next($r);
            if($result->getStatusCode()<400) DB::table('idempotency_keys')->where('id',$record->id)->update(['response'=>$result->getContent(),'http_status'=>$result->getStatusCode(),'updated_at'=>now()]);
            return $result;
        },3);
    }
}
