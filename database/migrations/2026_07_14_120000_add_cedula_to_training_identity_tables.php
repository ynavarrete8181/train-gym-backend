<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.rm_registros
    ADD COLUMN IF NOT EXISTS cedula VARCHAR(30) NULL;

ALTER TABLE entrenamiento.evaluaciones
    ADD COLUMN IF NOT EXISTS cedula VARCHAR(30) NULL;

UPDATE entrenamiento.rm_registros r
SET cedula = COALESCE(r.cedula, p.numero_identificacion)
FROM core.personas p
WHERE p.id = r.persona_id;

UPDATE entrenamiento.evaluaciones e
SET cedula = COALESCE(e.cedula, p.numero_identificacion)
FROM core.personas p
WHERE p.id = e.persona_id;

CREATE INDEX IF NOT EXISTS rm_registros_persona_cedula_idx
    ON entrenamiento.rm_registros (persona_id, cedula);

CREATE INDEX IF NOT EXISTS evaluaciones_persona_cedula_idx
    ON entrenamiento.evaluaciones (persona_id, cedula);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS entrenamiento.evaluaciones_persona_cedula_idx;
DROP INDEX IF EXISTS entrenamiento.rm_registros_persona_cedula_idx;

ALTER TABLE entrenamiento.evaluaciones
    DROP COLUMN IF EXISTS cedula;

ALTER TABLE entrenamiento.rm_registros
    DROP COLUMN IF EXISTS cedula;
SQL);
    }
};
