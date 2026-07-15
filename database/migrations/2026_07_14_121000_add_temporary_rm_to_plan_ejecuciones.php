<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.plan_ejecuciones
    ADD COLUMN IF NOT EXISTS rm_estimado_temporal NUMERIC(10,2) NULL;

CREATE INDEX IF NOT EXISTS plan_ejecuciones_rm_temporal_idx
    ON entrenamiento.plan_ejecuciones (persona_id, rm_estimado_temporal);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS entrenamiento.plan_ejecuciones_rm_temporal_idx;

ALTER TABLE entrenamiento.plan_ejecuciones
    DROP COLUMN IF EXISTS rm_estimado_temporal;
SQL);
    }
};
