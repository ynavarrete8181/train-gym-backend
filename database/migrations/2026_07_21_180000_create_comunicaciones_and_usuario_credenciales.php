<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS comunicaciones;

ALTER TABLE seguridad.usuarios
    ADD COLUMN IF NOT EXISTS requiere_cambio_password BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS password_temporal_generada_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS ultimo_login_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS email_credenciales VARCHAR(150);

CREATE TABLE IF NOT EXISTS comunicaciones.plantillas (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(80) NOT NULL UNIQUE,
    nombre VARCHAR(160) NOT NULL,
    asunto VARCHAR(220) NOT NULL,
    cuerpo TEXT NOT NULL,
    variables JSONB NOT NULL DEFAULT '[]'::jsonb,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS comunicaciones.envios (
    id BIGSERIAL PRIMARY KEY,
    plantilla_codigo VARCHAR(80),
    canal VARCHAR(30) NOT NULL DEFAULT 'EMAIL',
    destinatario VARCHAR(180) NOT NULL,
    asunto VARCHAR(220),
    mensaje TEXT,
    estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
    error TEXT,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    enviado_at TIMESTAMPTZ,
    created_id_user BIGINT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_comunicaciones_envios_estado
    ON comunicaciones.envios (estado, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_comunicaciones_envios_destinatario
    ON comunicaciones.envios (destinatario, created_at DESC);

INSERT INTO comunicaciones.plantillas (codigo, nombre, asunto, cuerpo, variables, activa, created_at, updated_at)
VALUES (
    'USUARIO_CREDENCIALES',
    'Credenciales de acceso',
    'Tu cuenta Revive fue creada',
    'Hola {nombre}, tu cuenta Revive fue creada. Usuario: {usuario}. Clave temporal: {clave_temporal}. Ingresa en {url_login} y cambia tu contraseña en el primer acceso.',
    '["nombre","usuario","clave_temporal","url_login"]'::jsonb,
    TRUE,
    NOW(),
    NOW()
)
ON CONFLICT (codigo) DO NOTHING;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS comunicaciones.idx_comunicaciones_envios_destinatario;
DROP INDEX IF EXISTS comunicaciones.idx_comunicaciones_envios_estado;
DROP TABLE IF EXISTS comunicaciones.envios;
DROP TABLE IF EXISTS comunicaciones.plantillas;

ALTER TABLE seguridad.usuarios
    DROP COLUMN IF EXISTS email_credenciales,
    DROP COLUMN IF EXISTS ultimo_login_at,
    DROP COLUMN IF EXISTS password_temporal_generada_at,
    DROP COLUMN IF EXISTS requiere_cambio_password;
SQL);
    }
};
