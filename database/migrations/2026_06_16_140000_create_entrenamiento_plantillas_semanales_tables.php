<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE entrenamiento.plan_ejercicios
    ADD COLUMN IF NOT EXISTS nombre_libre VARCHAR(150);

ALTER TABLE entrenamiento.plan_ejercicio_transferencias
    ADD COLUMN IF NOT EXISTS nombre_libre VARCHAR(150);

ALTER TABLE entrenamiento.plan_ejercicios
    ALTER COLUMN ejercicio_id DROP NOT NULL;

ALTER TABLE entrenamiento.plan_ejercicio_transferencias
    ALTER COLUMN ejercicio_id DROP NOT NULL;

CREATE TABLE IF NOT EXISTS entrenamiento.plantillas_semanales (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    objetivo TEXT,
    disciplina VARCHAR(80),
    total_dias INTEGER NOT NULL DEFAULT 5,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.plantilla_semana_dias (
    id BIGSERIAL PRIMARY KEY,
    plantilla_id BIGINT NOT NULL REFERENCES entrenamiento.plantillas_semanales(id) ON DELETE CASCADE,
    orden_dia INTEGER NOT NULL,
    dia VARCHAR(30) NOT NULL,
    nombre_sesion VARCHAR(150),
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT plantilla_semana_dias_unique UNIQUE (plantilla_id, orden_dia, dia)
);

CREATE INDEX IF NOT EXISTS plantilla_semana_dias_idx
    ON entrenamiento.plantilla_semana_dias(plantilla_id, orden_dia);

CREATE TABLE IF NOT EXISTS entrenamiento.plantilla_semana_bloques (
    id BIGSERIAL PRIMARY KEY,
    plantilla_dia_id BIGINT NOT NULL REFERENCES entrenamiento.plantilla_semana_dias(id) ON DELETE CASCADE,
    nombre VARCHAR(120) NOT NULL,
    tipo_bloque VARCHAR(60),
    orden INTEGER NOT NULL DEFAULT 1,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.plantilla_semana_ejercicios (
    id BIGSERIAL PRIMARY KEY,
    plantilla_bloque_id BIGINT NOT NULL REFERENCES entrenamiento.plantilla_semana_bloques(id) ON DELETE CASCADE,
    ejercicio_id BIGINT REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL,
    nombre_libre VARCHAR(150),
    orden INTEGER NOT NULL DEFAULT 1,
    lado VARCHAR(20),
    observaciones TEXT,
    usa_rm BOOLEAN NOT NULL DEFAULT FALSE,
    modo_prescripcion VARCHAR(30) NOT NULL DEFAULT 'POR_SERIE',
    descanso_segundos INTEGER,
    tempo VARCHAR(30),
    rpe_objetivo NUMERIC(4,2),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.plantilla_semana_ejercicio_series (
    id BIGSERIAL PRIMARY KEY,
    plantilla_ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.plantilla_semana_ejercicios(id) ON DELETE CASCADE,
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

CREATE TABLE IF NOT EXISTS entrenamiento.plantilla_semana_ejercicio_transferencias (
    id BIGSERIAL PRIMARY KEY,
    plantilla_ejercicio_id BIGINT NOT NULL REFERENCES entrenamiento.plantilla_semana_ejercicios(id) ON DELETE CASCADE,
    ejercicio_id BIGINT REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL,
    nombre_libre VARCHAR(150),
    orden INTEGER NOT NULL DEFAULT 1,
    modo_aplicacion VARCHAR(30) NOT NULL DEFAULT 'POR_CADA_SERIE',
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entrenamiento.plantilla_semana_transferencia_series (
    id BIGSERIAL PRIMARY KEY,
    transferencia_id BIGINT NOT NULL REFERENCES entrenamiento.plantilla_semana_ejercicio_transferencias(id) ON DELETE CASCADE,
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
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.plantilla_semana_transferencia_series;
DROP TABLE IF EXISTS entrenamiento.plantilla_semana_ejercicio_transferencias;
DROP TABLE IF EXISTS entrenamiento.plantilla_semana_ejercicio_series;
DROP TABLE IF EXISTS entrenamiento.plantilla_semana_ejercicios;
DROP TABLE IF EXISTS entrenamiento.plantilla_semana_bloques;
DROP TABLE IF EXISTS entrenamiento.plantilla_semana_dias;
DROP TABLE IF EXISTS entrenamiento.plantillas_semanales;

ALTER TABLE entrenamiento.plan_ejercicio_transferencias
    DROP COLUMN IF EXISTS nombre_libre;

ALTER TABLE entrenamiento.plan_ejercicios
    DROP COLUMN IF EXISTS nombre_libre;
SQL);
    }
};
