<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh domain schemas already have this check. The final-source upgrade
        // added orientation_hours to older schemas without its numeric invariant.
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'risk_plans'::regclass
          AND conname = 'risk_plans_orientation_hours_check'
    ) THEN
        ALTER TABLE risk_plans ADD CONSTRAINT risk_plans_orientation_hours_check
            CHECK (orientation_hours >= 0);
    END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        // The check is also owned by the original fresh-schema migration.
        throw new RuntimeException('Tarihsel kayıtlar için belgelenmiş yedekten geri dönüş uygulayın.');
    }
};
