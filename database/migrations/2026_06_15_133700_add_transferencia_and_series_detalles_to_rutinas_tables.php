<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.rutinas
    ADD COLUMN IF NOT EXISTS ejercicio_transferencia_id BIGINT REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS repeticiones_transferencia INTEGER,
    ADD COLUMN IF NOT EXISTS series_detalles JSONB;

ALTER TABLE entrenamiento.rutina_plantilla_detalles
    ADD COLUMN IF NOT EXISTS ejercicio_transferencia_id BIGINT REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS repeticiones_transferencia INTEGER,
    ADD COLUMN IF NOT EXISTS series_detalles JSONB;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.rutina_plantilla_detalles
    DROP COLUMN IF EXISTS ejercicio_transferencia_id,
    DROP COLUMN IF EXISTS repeticiones_transferencia,
    DROP COLUMN IF EXISTS series_detalles;

ALTER TABLE entrenamiento.rutinas
    DROP COLUMN IF EXISTS ejercicio_transferencia_id,
    DROP COLUMN IF EXISTS repeticiones_transferencia,
    DROP COLUMN IF EXISTS series_detalles;
SQL);
    }
};
