<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // present|array permits an empty array, but validation rejects SQL NULL.
        // Preserve that existing contract on schemas created before this fix.
        $nullable=[];
        foreach(DB::table('information_schema.columns')->where('table_schema','public')->where('is_nullable','YES')->get(['table_name','column_name']) as $column){
            $nullable[$column->table_name.':'.$column->column_name]=true;
        }
        foreach(config('domain') as $table=>$spec){
            foreach($spec['fields'] as $field=>$definition){
                if($definition['type']==='jsonb' && str_starts_with($definition['rules'],'present') && isset($nullable[$table.':'.$field])){
                    DB::statement('ALTER TABLE "'.$table.'" ALTER COLUMN "'.$field.'" SET NOT NULL');
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Tarihsel kayıtlar için belgelenmiş yedekten geri dönüş uygulayın.');
    }
};
