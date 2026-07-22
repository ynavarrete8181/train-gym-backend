<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS notificaciones;

CREATE TABLE IF NOT EXISTS notificaciones.configuracion_cumpleanos (
    id SMALLINT PRIMARY KEY DEFAULT 1,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    hora_envio TIME NOT NULL DEFAULT '07:00:00',
    titulo VARCHAR(160) NOT NULL DEFAULT 'Feliz cumpleanos de parte de Revive',
    mensaje TEXT NOT NULL DEFAULT 'Hola {nombre}, todo el equipo Revive te desea un feliz cumpleanos. Que tengas un excelente dia.',
    updated_by_usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_configuracion_cumpleanos_singleton CHECK (id = 1)
);

INSERT INTO notificaciones.configuracion_cumpleanos (id)
VALUES (1)
ON CONFLICT (id) DO NOTHING;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS notificaciones.configuracion_cumpleanos;
SQL);
    }
};
