<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE notificaciones.campanias (
            id BIGSERIAL PRIMARY KEY, nombre VARCHAR(180) NOT NULL, descripcion TEXT NULL,
            plantilla_id BIGINT NULL REFERENCES notificaciones.plantillas(id) ON DELETE SET NULL,
            asunto VARCHAR(255) NOT NULL, cuerpo_html TEXT NOT NULL, estado VARCHAR(35) NOT NULL DEFAULT 'BORRADOR',
            criterios JSONB NOT NULL DEFAULT '{}', total_destinatarios INTEGER NOT NULL DEFAULT 0,
            total_enviados INTEGER NOT NULL DEFAULT 0, total_errores INTEGER NOT NULL DEFAULT 0,
            programada_at TIMESTAMP NULL, iniciada_at TIMESTAMP NULL, finalizada_at TIMESTAMP NULL,
            created_by BIGINT NULL REFERENCES seguridad.users(id) ON DELETE SET NULL,
            created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement("CREATE TABLE notificaciones.campania_destinatarios (
            id BIGSERIAL PRIMARY KEY, campania_id BIGINT NOT NULL REFERENCES notificaciones.campanias(id) ON DELETE CASCADE,
            usuario_id BIGINT NULL REFERENCES seguridad.users(id) ON DELETE SET NULL,
            correo_destino VARCHAR(255) NOT NULL, nombre_destinatario VARCHAR(180) NULL,
            variables JSONB NOT NULL DEFAULT '{}', estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
            numero_intentos INTEGER NOT NULL DEFAULT 0, ultimo_error TEXT NULL, enviado_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(campania_id, correo_destino)
        )");
        DB::statement('CREATE TABLE notificaciones.campania_intentos (
            id BIGSERIAL PRIMARY KEY, destinatario_id BIGINT NOT NULL REFERENCES notificaciones.campania_destinatarios(id) ON DELETE CASCADE,
            numero_intento INTEGER NOT NULL, estado VARCHAR(30) NOT NULL, proveedor VARCHAR(80), http_status INTEGER,
            respuesta_sanitizada JSONB, mensaje_error TEXT, iniciado_at TIMESTAMP NULL, finalizado_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW(), UNIQUE(destinatario_id, numero_intento)
        )');
        DB::statement('CREATE INDEX campanias_estado_idx ON notificaciones.campanias(estado)');
        DB::statement('CREATE INDEX campania_destinatarios_estado_idx ON notificaciones.campania_destinatarios(campania_id, estado)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS notificaciones.campania_intentos');
        DB::statement('DROP TABLE IF EXISTS notificaciones.campania_destinatarios');
        DB::statement('DROP TABLE IF EXISTS notificaciones.campanias');
    }
};
