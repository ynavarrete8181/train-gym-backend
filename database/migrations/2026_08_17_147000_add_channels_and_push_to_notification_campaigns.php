<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE notificaciones.campanias ADD COLUMN IF NOT EXISTS canal_interno BOOLEAN NOT NULL DEFAULT FALSE");
        DB::statement("ALTER TABLE notificaciones.campanias ADD COLUMN IF NOT EXISTS canal_correo BOOLEAN NOT NULL DEFAULT TRUE");
        DB::statement("ALTER TABLE notificaciones.campanias ADD COLUMN IF NOT EXISTS canal_push BOOLEAN NOT NULL DEFAULT FALSE");

        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ALTER COLUMN correo_destino DROP NOT NULL");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS estado_interno VARCHAR(30) NOT NULL DEFAULT 'OMITIDO'");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS estado_correo VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE'");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS estado_push VARCHAR(30) NOT NULL DEFAULT 'OMITIDO'");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS error_interno TEXT NULL");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS error_push TEXT NULL");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS interno_at TIMESTAMP NULL");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios ADD COLUMN IF NOT EXISTS push_at TIMESTAMP NULL");

        DB::statement("UPDATE notificaciones.campania_destinatarios SET estado_correo = CASE
            WHEN estado = 'ENVIADA' THEN 'ENVIADA'
            WHEN estado = 'ERROR' THEN 'ERROR'
            WHEN estado = 'PROCESANDO' THEN 'PROCESANDO'
            WHEN estado = 'EN_COLA' THEN 'EN_COLA'
            ELSE 'PENDIENTE'
        END");

        DB::statement("ALTER TABLE notificaciones.campania_destinatarios DROP CONSTRAINT IF EXISTS campania_destinatarios_campania_id_correo_destino_key");
        DB::statement("ALTER TABLE notificaciones.campania_destinatarios DROP CONSTRAINT IF EXISTS campania_destinatarios_campania_id_correo_destino_unique");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS campania_destinatarios_campania_correo_unique ON notificaciones.campania_destinatarios(campania_id, correo_destino) WHERE correo_destino IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS campania_destinatarios_campania_usuario_unique ON notificaciones.campania_destinatarios(campania_id, usuario_id) WHERE usuario_id IS NOT NULL");

        DB::statement("CREATE TABLE IF NOT EXISTS notificaciones.dispositivos_push (
            id BIGSERIAL PRIMARY KEY,
            usuario_id BIGINT NOT NULL REFERENCES seguridad.users(id) ON DELETE CASCADE,
            token VARCHAR(255) NOT NULL UNIQUE,
            plataforma VARCHAR(20) NULL,
            activo BOOLEAN NOT NULL DEFAULT TRUE,
            ultimo_registro_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement("CREATE INDEX IF NOT EXISTS dispositivos_push_usuario_activo_idx ON notificaciones.dispositivos_push(usuario_id, activo)");

        $proveedorId = DB::table('integraciones.proveedores')->where('codigo', 'EXPO_PUSH')->value('id');
        if (! $proveedorId) {
            $proveedorId = DB::table('integraciones.proveedores')->insertGetId([
                'codigo' => 'EXPO_PUSH',
                'nombre' => 'Expo Push',
                'descripcion' => 'Proveedor para notificaciones push de la aplicación móvil.',
                'url_base' => 'https://exp.host',
                'tipo' => 'REST',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('integraciones.servicios')->updateOrInsert(
            ['codigo' => 'PUSH_ENVIAR'],
            [
                'proveedor_id' => $proveedorId,
                'nombre' => 'Enviar notificación push',
                'endpoint' => '/--/api/v2/push/send',
                'metodo' => 'POST',
                'tipo_autenticacion' => 'NINGUNA',
                'headers' => json_encode(['Accept' => 'application/json', 'Content-Type' => 'application/json']),
                'timeout_segundos' => 20,
                'reintentos' => 3,
                'configuracion' => json_encode([]),
                'activo' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('integraciones.servicios')->where('codigo', 'PUSH_ENVIAR')->delete();
        DB::table('integraciones.proveedores')->where('codigo', 'EXPO_PUSH')->delete();
        DB::statement('DROP TABLE IF EXISTS notificaciones.dispositivos_push');
        DB::statement('DROP INDEX IF EXISTS notificaciones.campania_destinatarios_campania_usuario_unique');
        DB::statement('DROP INDEX IF EXISTS notificaciones.campania_destinatarios_campania_correo_unique');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS push_at');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS interno_at');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS error_push');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS error_interno');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS estado_push');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS estado_correo');
        DB::statement('ALTER TABLE notificaciones.campania_destinatarios DROP COLUMN IF EXISTS estado_interno');
        DB::statement('ALTER TABLE notificaciones.campanias DROP COLUMN IF EXISTS canal_push');
        DB::statement('ALTER TABLE notificaciones.campanias DROP COLUMN IF EXISTS canal_correo');
        DB::statement('ALTER TABLE notificaciones.campanias DROP COLUMN IF EXISTS canal_interno');
    }
};
