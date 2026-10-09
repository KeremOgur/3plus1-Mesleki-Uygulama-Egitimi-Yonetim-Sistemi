<?php
namespace App\Domain;
use App\Models\DomainRecord;
use Illuminate\Support\Facades\DB;

class Records
{
    public static function query(string $type) { return DomainRecord::for($type)->newQuery(); }
    public static function get(string $type, string $id): DomainRecord { abort_unless(\Illuminate\Support\Str::isUuid($id),404,'Kayıt bulunamadı.'); return self::query($type)->findOrFail($id); }
    public static function create(string $type, array $data): DomainRecord
    {
        $data['created_by'] ??= auth()->id();
        $data['retained_until'] ??= now()->addYears(5);
        $r = DomainRecord::for($type); $r->fill($data); $r->save(); return $r->refresh();
    }
    public static function auditContext(?int $actor, ?string $assignment, ?string $reason=null): void
    {
        $reason=$reason?'Gerekçe kaydı SHA256: '.hash('sha256',$reason):null;
        foreach (['actor'=>(string)$actor,'assignment'=>(string)$assignment,'reason'=>(string)$reason,'request'=>(string)request()->attributes->get('request_id')] as $k=>$v) {
            DB::select("SELECT set_config(?, ?, true)", ["mue.$k",$v]);
        }
    }
    public static function scope(DomainRecord $r): array
    {
        $scope=array_intersect_key($r->getAttributes(),array_flip(['institution_id','program_id','term_id','company_id','student_id','placement_id']));
        $self=match($r->getTable()) {'institutions'=>'institution_id','programs'=>'program_id','academic_terms'=>'term_id','companies'=>'company_id','students'=>'student_id','placements'=>'placement_id',default=>null};
        if($self && $r->id) $scope[$self]=$r->id;
        return $scope;
    }
}
