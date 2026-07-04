<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS entrenamiento.planes (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    nombre VARCHAR(150) NOT NULL,
    objetivo TEXT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE,
    estado VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.rutinas (
    id BIGSERIAL PRIMARY KEY,
    plan_id BIGINT NOT NULL REFERENCES entrenamiento.planes(id) ON DELETE CASCADE,
    semana INTEGER NOT NULL,
    dia VARCHAR(30) NOT NULL,
    bloque VARCHAR(120),
    ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.ejercicios(id),
    series INTEGER NOT NULL DEFAULT 1,
    repeticiones VARCHAR(50),
    carga_objetivo NUMERIC(10,2),
    tipo_carga VARCHAR(30) DEFAULT 'LIBRE',
    descanso_segundos INTEGER,
    notas TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.rutinas;
DROP TABLE IF EXISTS entrenamiento.planes;
SQL);
    }
};
