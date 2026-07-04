<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS entrenamiento.plan_dias (
    id BIGSERIAL PRIMARY KEY,
    plan_id BIGINT NOT NULL REFERENCES entrenamiento.planes(id) ON DELETE CASCADE,
    semana INTEGER NOT NULL,
    dia VARCHAR(30) NOT NULL,
    nombre_sesion VARCHAR(150),
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT plan_dias_plan_semana_dia_unique UNIQUE (plan_id, semana, dia)
);

CREATE INDEX IF NOT EXISTS plan_dias_plan_idx
    ON entrenamiento.plan_dias(plan_id, semana, dia);

CREATE TABLE IF NOT EXISTS entrenamiento.plan_bloques (
    id BIGSERIAL PRIMARY KEY,
    plan_dia_id BIGINT NOT NULL REFERENCES entrenamiento.plan_dias(id) ON DELETE CASCADE,
    nombre VARCHAR(120) NOT NULL,
    tipo_bloque VARCHAR(60),
    orden INTEGER NOT NULL DEFAULT 1,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS plan_bloques_plan_dia_idx
    ON entrenamiento.plan_bloques(plan_dia_id, orden);

CREATE TABLE IF NOT EXISTS entrenamiento.plan_ejercicios (
    id BIGSERIAL PRIMARY KEY,
    plan_bloque_id BIGINT NOT NULL REFERENCES entrenamiento.plan_bloques(id) ON DELETE CASCADE,
    ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.ejercicios(id),
    orden INTEGER NOT NULL DEFAULT 1,
    lado VARCHAR(20),
    observaciones TEXT,
    usa_rm BOOLEAN NOT NULL DEFAULT FALSE,
    rm_referencia NUMERIC(10,2),
    rm_registro_id BIGINT REFERENCES entrenamiento.rm_registros(id) ON DELETE SET NULL,
    modo_prescripcion VARCHAR(30) NOT NULL DEFAULT 'POR_SERIE',
    descanso_segundos INTEGER,
    tempo VARCHAR(30),
    rpe_objetivo NUMERIC(4,2),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS plan_ejercicios_bloque_idx
    ON entrenamiento.plan_ejercicios(plan_bloque_id, orden);

CREATE INDEX IF NOT EXISTS plan_ejercicios_rm_idx
    ON entrenamiento.plan_ejercicios(rm_registro_id);

CREATE TABLE IF NOT EXISTS entrenamiento.plan_ejercicio_series (
    id BIGSERIAL PRIMARY KEY,
    plan_ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE,
    numero_serie INTEGER NOT NULL,
    tipo_carga VARCHAR(30) NOT NULL DEFAULT 'LIBRE',
    porcentaje_rm NUMERIC(5,2),
    carga_fija NUMERIC(10,2),
    unidad_carga VARCHAR(20),
    repeticiones VARCHAR(50),
    tiempo_segundos INTEGER,
    distancia_metros NUMERIC(10,2),
    rpe NUMERIC(4,2),
    descanso_segundos INTEGER,
    tempo VARCHAR(30),
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS plan_ejercicio_series_ejercicio_idx
    ON entrenamiento.plan_ejercicio_series(plan_ejercicio_id, numero_serie);

CREATE TABLE IF NOT EXISTS entrenamiento.plan_ejercicio_transferencias (
    id BIGSERIAL PRIMARY KEY,
    plan_ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE,
    ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.ejercicios(id),
    orden INTEGER NOT NULL DEFAULT 1,
    modo_aplicacion VARCHAR(30) NOT NULL DEFAULT 'POR_CADA_SERIE',
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS plan_ejercicio_transferencias_ejercicio_idx
    ON entrenamiento.plan_ejercicio_transferencias(plan_ejercicio_id, orden);

CREATE TABLE IF NOT EXISTS entrenamiento.plan_transferencia_series (
    id BIGSERIAL PRIMARY KEY,
    transferencia_id BIGINT NOT NULL REFERENCES entrenamiento.plan_ejercicio_transferencias(id) ON DELETE CASCADE,
    numero_serie INTEGER NOT NULL,
    tipo_carga VARCHAR(30) NOT NULL DEFAULT 'LIBRE',
    porcentaje_rm NUMERIC(5,2),
    carga_fija NUMERIC(10,2),
    unidad_carga VARCHAR(20),
    repeticiones VARCHAR(50),
    tiempo_segundos INTEGER,
    distancia_metros NUMERIC(10,2),
    rpe NUMERIC(4,2),
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS plan_transferencia_series_transferencia_idx
    ON entrenamiento.plan_transferencia_series(transferencia_id, numero_serie);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.plan_transferencia_series;
DROP TABLE IF EXISTS entrenamiento.plan_ejercicio_transferencias;
DROP TABLE IF EXISTS entrenamiento.plan_ejercicio_series;
DROP TABLE IF EXISTS entrenamiento.plan_ejercicios;
DROP TABLE IF EXISTS entrenamiento.plan_bloques;
DROP TABLE IF EXISTS entrenamiento.plan_dias;
SQL);
    }
};
