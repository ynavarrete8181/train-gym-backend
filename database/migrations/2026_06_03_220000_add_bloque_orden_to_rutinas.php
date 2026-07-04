<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.rutinas
    ADD COLUMN IF NOT EXISTS bloque_orden INTEGER DEFAULT 1;

ALTER TABLE entrenamiento.rutina_plantilla_detalles
    ADD COLUMN IF NOT EXISTS bloque_orden INTEGER DEFAULT 1;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.rutinas
    DROP COLUMN IF EXISTS bloque_orden;

ALTER TABLE entrenamiento.rutina_plantilla_detalles
    DROP COLUMN IF EXISTS bloque_orden;
SQL);
    }
};
