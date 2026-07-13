<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.plan_ejecuciones
    ADD COLUMN IF NOT EXISTS persona_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS usuario_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS cedula VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS semana INTEGER NULL,
    ADD COLUMN IF NOT EXISTS dia VARCHAR(20) NULL;

UPDATE entrenamiento.plan_ejecuciones pe
SET
    persona_id = COALESCE(pe.persona_id, p.persona_id),
    cedula = COALESCE(pe.cedula, cp.numero_identificacion),
    usuario_id = COALESCE(pe.usuario_id, su.id)
FROM entrenamiento.planes p
LEFT JOIN core.personas cp ON cp.id = p.persona_id
LEFT JOIN seguridad.usuarios su ON su.persona_id = p.persona_id
WHERE p.id = pe.plan_id;

UPDATE entrenamiento.plan_ejecuciones pe
SET
    semana = COALESCE(pe.semana, pd.semana),
    dia = COALESCE(pe.dia, LOWER(pd.dia))
FROM entrenamiento.plan_ejercicios peje
JOIN entrenamiento.plan_bloques pb ON pb.id = peje.plan_bloque_id
JOIN entrenamiento.plan_dias pd ON pd.id = pb.plan_dia_id
WHERE peje.id = pe.plan_ejercicio_id;

ALTER TABLE entrenamiento.plan_ejecuciones
    DROP CONSTRAINT IF EXISTS plan_ejecuciones_unique_ejercicio_fecha;

DROP INDEX IF EXISTS entrenamiento.plan_ejecuciones_unique_persona_semana_dia;

CREATE UNIQUE INDEX plan_ejecuciones_unique_persona_semana_dia
    ON entrenamiento.plan_ejecuciones (
        plan_id,
        plan_ejercicio_id,
        COALESCE(cedula, ''),
        COALESCE(semana, 0),
        COALESCE(dia, '')
    );

CREATE INDEX IF NOT EXISTS plan_ejecuciones_persona_idx
    ON entrenamiento.plan_ejecuciones (persona_id);

CREATE INDEX IF NOT EXISTS plan_ejecuciones_cedula_idx
    ON entrenamiento.plan_ejecuciones (cedula);

CREATE INDEX IF NOT EXISTS plan_ejecuciones_semana_dia_idx
    ON entrenamiento.plan_ejecuciones (plan_id, semana, dia);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS entrenamiento.plan_ejecuciones_semana_dia_idx;
DROP INDEX IF EXISTS entrenamiento.plan_ejecuciones_cedula_idx;
DROP INDEX IF EXISTS entrenamiento.plan_ejecuciones_persona_idx;
DROP INDEX IF EXISTS entrenamiento.plan_ejecuciones_unique_persona_semana_dia;

ALTER TABLE entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_unique_ejercicio_fecha UNIQUE (plan_ejercicio_id, fecha_ejecucion),
    DROP COLUMN IF EXISTS dia,
    DROP COLUMN IF EXISTS semana,
    DROP COLUMN IF EXISTS cedula,
    DROP COLUMN IF EXISTS usuario_id,
    DROP COLUMN IF EXISTS persona_id;
SQL);
    }
};
