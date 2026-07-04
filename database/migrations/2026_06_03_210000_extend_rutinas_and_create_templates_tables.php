<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.rutinas
    ADD COLUMN IF NOT EXISTS unidad_objetivo VARCHAR(20),
    ADD COLUMN IF NOT EXISTS tempo VARCHAR(30),
    ADD COLUMN IF NOT EXISTS rpe NUMERIC(4,1),
    ADD COLUMN IF NOT EXISTS orden INTEGER DEFAULT 1;

CREATE TABLE IF NOT EXISTS entrenamiento.rutina_plantillas (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    objetivo TEXT,
    descripcion TEXT,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.rutina_plantilla_detalles (
    id BIGSERIAL PRIMARY KEY,
    plantilla_id BIGINT NOT NULL REFERENCES entrenamiento.rutina_plantillas(id) ON DELETE CASCADE,
    dia VARCHAR(30) NOT NULL,
    bloque VARCHAR(120),
    ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.ejercicios(id),
    series INTEGER NOT NULL DEFAULT 1,
    repeticiones VARCHAR(50),
    carga_objetivo NUMERIC(10,2),
    tipo_carga VARCHAR(30) DEFAULT 'LIBRE',
    unidad_objetivo VARCHAR(20),
    tempo VARCHAR(30),
    rpe NUMERIC(4,1),
    descanso_segundos INTEGER,
    orden INTEGER DEFAULT 1,
    notas TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.rutina_plantilla_detalles;
DROP TABLE IF EXISTS entrenamiento.rutina_plantillas;

ALTER TABLE entrenamiento.rutinas
    DROP COLUMN IF EXISTS unidad_objetivo,
    DROP COLUMN IF EXISTS tempo,
    DROP COLUMN IF EXISTS rpe,
    DROP COLUMN IF EXISTS orden;
SQL);
    }
};
