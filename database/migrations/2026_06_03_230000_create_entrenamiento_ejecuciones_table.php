<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS entrenamiento.ejecuciones (
    id BIGSERIAL PRIMARY KEY,
    plan_id BIGINT NOT NULL REFERENCES entrenamiento.planes(id) ON DELETE CASCADE,
    rutina_id BIGINT NOT NULL REFERENCES entrenamiento.rutinas(id) ON DELETE CASCADE,
    fecha_ejecucion DATE NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    series_completadas INTEGER,
    repeticiones_reales VARCHAR(50),
    carga_real NUMERIC(10,2),
    unidad_carga_real VARCHAR(20),
    rpe_real NUMERIC(4,1),
    dolor_nivel INTEGER,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT ejecuciones_unique_rutina_fecha UNIQUE (rutina_id, fecha_ejecucion)
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.ejecuciones;
SQL);
    }
};
