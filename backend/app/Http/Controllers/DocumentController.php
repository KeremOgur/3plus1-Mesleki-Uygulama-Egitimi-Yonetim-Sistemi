<?php
namespace App\Http\Controllers;
use App\Domain\{Records,DomainError,ScopeAccess};
use App\Jobs\ScanDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate,Storage};
use Illuminate\Support\Str;
class DocumentController extends ResourceController
{
    public function upload(Request $req)
    {
        $req->validate(['file'=>'bail|required|file|max:20480|mimes:pdf,jpg,jpeg,png,docx','target_type'=>'required|string','target_id'=>'required|uuid','classification'=>'required|in:kurum_ici,gizli,saglik,disiplin','retention_start_event'=>'required|string']);
        $target=Records::get($req->target_type,$req->target_id); Gate::authorize('record-view',$target);
        $candidate=\App\Models\DomainRecord::for('documents')->fill(array_merge(Records::scope($target),['target_type'=>$req->target_type,'target_id'=>$target->id,'classification'=>$req->classification]));
        Gate::authorize('record-write',$candidate);
        $file=$req->file('file'); $extension=strtolower($file->getClientOriginalExtension());
        $contentMime=(new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $expected=['pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip']];
        if(!isset($expected[$extension]) || !in_array($contentMime,$expected[$extension])) throw new DomainError('DOSYA_TURU','Dosyanın içeriği ile uzantısı uyuşmuyor.',422);
        if($extension==='docx') {
            $zip=new \ZipArchive; if($zip->open($file->getRealPath())!==true || $zip->locateName('word/document.xml')===false) throw new DomainError('DOSYA_TURU','Geçerli Word belgesi bulunamadı.',422);
            $zip->close();
        }
        $key='karantina/'.Str::uuid().'.'.$extension;
        if(!Storage::disk('local')->put($key,file_get_contents($file->getRealPath()))) throw new DomainError('DEPOLAMA_HATASI','Belge özel depoya yazılamadı.',503);
        try {
            return $this->transaction($req,function($a)use($req,$target,$file,$key){
                if(in_array($req->classification,['saglik','disiplin']) && !in_array($a->role,['ogrenci','mudur','mue_komisyon','belge_gorevlisi'])) throw new DomainError('FORBIDDEN_SCOPE','Bu belge sınıfını yüklemeye yetkiniz yok.',403);
                $doc=Records::create('documents',array_merge(Records::scope($target),['target_type'=>$req->target_type,'target_id'=>$target->id,'object_key'=>$key,'original_name'=>basename($file->getClientOriginalName()),'mime'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath()),'scan_status'=>'bekliyor','classification'=>$req->classification,'retention_start_event'=>$req->retention_start_event,'retention_until'=>now()->addYears(5)->toDateString(),'legal_hold'=>false,'state'=>'karantina']));
                ScanDocument::dispatch($doc->id)->afterCommit(); return response()->json($this->present($a->role,$doc),201);
            });
        } catch(\Throwable $e) { Storage::disk('local')->delete($key); throw $e; }
    }
    public function download(Request $req,string $id)
    {
        $doc=Records::get('documents',$id); Gate::authorize('record-view',$doc);
        $target=Records::get($doc->target_type,$doc->target_id); Gate::authorize('record-view',$target);
        $a=app(ScopeAccess::class)->assignment($req->user());
        if(in_array($doc->classification,['saglik','disiplin']) && !in_array($a->role,['ogrenci','mudur','mue_komisyon','belge_gorevlisi'])) throw new DomainError('FORBIDDEN_SCOPE','Kısıtlı belgeye erişim yetkiniz yok.',403);
        if(!$this->visible($a->role,$target)) throw new DomainError('FORBIDDEN_SCOPE','Henüz ilan edilmemiş belgeye erişilemez.',403);
        if($doc->scan_status!=='temiz') throw new DomainError('DOSYA_KARANTINA','Belge tarama işlemi tamamlanmadan indirilemez.');
        if(!Storage::disk('local')->exists($doc->object_key)) throw new DomainError('DOSYA_EKSIK','Belge içeriği depoda bulunamadı.',404);
        if(hash_file('sha256',Storage::disk('local')->path($doc->object_key))!==$doc->sha256) throw new DomainError('DOSYA_BUTUNLUGU','Belge bütünlüğü doğrulanamadı.');
        $this->transaction($req,function($a)use($doc){\Illuminate\Support\Facades\DB::table('audit_events')->insert(['actor_id'=>auth()->id(),'assignment_id'=>$a->id,'institution_id'=>$doc->institution_id,'action'=>'DOWNLOAD','target_type'=>'documents','target_id'=>$doc->id,'request_id'=>request()->attributes->get('request_id'),'occurred_at'=>now()]);});
        return Storage::disk('local')->download($doc->object_key,$doc->original_name,['Content-Type'=>$doc->mime,'X-Content-Type-Options'=>'nosniff']);
    }
}
