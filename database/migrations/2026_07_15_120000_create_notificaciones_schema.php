<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS notificaciones;

CREATE TABLE IF NOT EXISTS notificaciones.notificaciones (
    id BIGSERIAL PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL DEFAULT 'GENERAL',
    titulo VARCHAR(160) NOT NULL,
    mensaje TEXT NOT NULL,
    data JSONB NOT NULL DEFAULT '{}'::jsonb,
    canal_default VARCHAR(30) NOT NULL DEFAULT 'APP',
    prioridad VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
    programada_para TIMESTAMPTZ,
    enviada_en TIMESTAMPTZ,
    created_by_usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS notificaciones.destinatarios (
    id BIGSERIAL PRIMARY KEY,
    notificacion_id BIGINT NOT NULL REFERENCES notificaciones.notificaciones(id) ON DELETE CASCADE,
    usuario_id BIGINT REFERENCES seguridad.usuarios(id) ON DELETE CASCADE,
    persona_id BIGINT REFERENCES core.personas(id) ON DELETE CASCADE,
    canal VARCHAR(30) NOT NULL DEFAULT 'APP',
    estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
    leida_at TIMESTAMPTZ,
    entregada_at TIMESTAMPTZ,
    error TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_notificaciones_destinatario CHECK (usuario_id IS NOT NULL OR persona_id IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS notificaciones.dispositivos_push (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT REFERENCES seguridad.usuarios(id) ON DELETE CASCADE,
    persona_id BIGINT REFERENCES core.personas(id) ON DELETE CASCADE,
    plataforma VARCHAR(30) NOT NULL,
    token TEXT NOT NULL UNIQUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    last_seen_at TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_notificaciones_dispositivo CHECK (usuario_id IS NOT NULL OR persona_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_notificaciones_destinatarios_usuario
    ON notificaciones.destinatarios (usuario_id, estado, leida_at);

CREATE INDEX IF NOT EXISTS idx_notificaciones_destinatarios_persona
    ON notificaciones.destinatarios (persona_id, estado, leida_at);

CREATE INDEX IF NOT EXISTS idx_notificaciones_notificaciones_tipo
    ON notificaciones.notificaciones (tipo, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_notificaciones_dispositivos_usuario
    ON notificaciones.dispositivos_push (usuario_id, activo);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS notificaciones.idx_notificaciones_dispositivos_usuario;
DROP INDEX IF EXISTS notificaciones.idx_notificaciones_notificaciones_tipo;
DROP INDEX IF EXISTS notificaciones.idx_notificaciones_destinatarios_persona;
DROP INDEX IF EXISTS notificaciones.idx_notificaciones_destinatarios_usuario;
DROP TABLE IF EXISTS notificaciones.dispositivos_push;
DROP TABLE IF EXISTS notificaciones.destinatarios;
DROP TABLE IF EXISTS notificaciones.notificaciones;
DROP SCHEMA IF EXISTS notificaciones;
SQL);
    }
};
