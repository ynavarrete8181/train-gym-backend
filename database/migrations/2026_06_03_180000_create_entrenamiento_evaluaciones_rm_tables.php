<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS entrenamiento.evaluaciones (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    tipo_evaluacion VARCHAR(50) NOT NULL,
    fecha_evaluacion DATE NOT NULL DEFAULT CURRENT_DATE,
    resultado_resumen TEXT,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.rm_registros (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.ejercicios(id),
    tipo_registro VARCHAR(20) NOT NULL DEFAULT 'ESTIMADO',
    peso NUMERIC(10,2) NOT NULL,
    repeticiones INTEGER,
    rm_estimado NUMERIC(10,2) NOT NULL,
    fecha_registro DATE NOT NULL DEFAULT CURRENT_DATE,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.rm_registros;
DROP TABLE IF EXISTS entrenamiento.evaluaciones;
SQL);
    }
};
