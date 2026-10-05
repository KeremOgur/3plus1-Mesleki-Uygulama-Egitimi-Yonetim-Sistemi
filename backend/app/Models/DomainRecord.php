<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DomainRecord extends Model
{
    use HasUuids;
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    public static function for(string $resource): static
    {
        abort_unless(config("domain.$resource"), 404, 'Kayıt türü bulunamadı.');
        $model = new static;
        $model->setTable($resource);
        $casts = [];
        foreach (config("domain.$resource.fields") as $key=>$field) {
            $cast = match ($field['type']) {'jsonb'=>'array','boolean'=>'boolean','integer','bigint'=>'integer','decimal'=>'float',default=>null};
            if ($cast) $casts[$key]=$cast;
        }
        if ($resource === 'students') foreach (['national_id','iban'] as $field) $casts[$field]='encrypted';
        if ($resource === 'trainer_qualifications') $casts['national_id']='encrypted';
        if ($resource === 'payroll') $casts['student_iban']='encrypted';
        $model->mergeCasts($casts);
        return $model;
    }

    public function newInstance($attributes = [], $exists = false)
    {
        $instance = parent::newInstance($attributes,$exists);
        $instance->mergeCasts($this->getCasts());
        return $instance;
    }
}
