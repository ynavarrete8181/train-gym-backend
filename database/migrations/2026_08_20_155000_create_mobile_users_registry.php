<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE seguridad.usuarios_app (
            id BIGSERIAL PRIMARY KEY,
            usuario_id BIGINT NOT NULL UNIQUE REFERENCES seguridad.users(id) ON DELETE CASCADE,
            plataforma VARCHAR(20) NULL,
            version_app VARCHAR(40) NULL,
            activo BOOLEAN NOT NULL DEFAULT TRUE,
            primer_acceso_at TIMESTAMP NOT NULL DEFAULT NOW(),
            ultimo_acceso_at TIMESTAMP NOT NULL DEFAULT NOW(),
            ultima_sincronizacion_at TIMESTAMP NULL,
            estado_sincronizacion VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
            origen_datos VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement('CREATE TABLE seguridad.usuario_app_vinculaciones (
            id BIGSERIAL PRIMARY KEY,
            usuario_app_id BIGINT NOT NULL REFERENCES seguridad.usuarios_app(id) ON DELETE CASCADE,
            tipo VARCHAR(30) NOT NULL,
            identificador_institucional VARCHAR(100) NULL,
            origen VARCHAR(50) NOT NULL,
            activo BOOLEAN NOT NULL DEFAULT TRUE,
            sincronizado_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(usuario_app_id, tipo)
        )');
        DB::statement('CREATE INDEX usuarios_app_activo_idx ON seguridad.usuarios_app(activo, ultimo_acceso_at)');
        DB::statement('CREATE INDEX usuario_app_vinculaciones_tipo_idx ON seguridad.usuario_app_vinculaciones(tipo, activo)');

        DB::statement("INSERT INTO seguridad.usuarios_app (usuario_id, activo, primer_acceso_at, ultimo_acceso_at, estado_sincronizacion, origen_datos, created_at, updated_at)
            SELECT id, TRUE, ultimo_acceso_app_at, ultimo_acceso_app_at, 'PENDIENTE', 'MIGRACION', NOW(), NOW()
            FROM seguridad.users WHERE ultimo_acceso_app_at IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS seguridad.usuario_app_vinculaciones');
        DB::statement('DROP TABLE IF EXISTS seguridad.usuarios_app');
    }
};
