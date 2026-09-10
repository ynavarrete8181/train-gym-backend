<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS integraciones');
        DB::statement('CREATE SCHEMA IF NOT EXISTS notificaciones');

        DB::statement("CREATE TABLE integraciones.proveedores (
            id BIGSERIAL PRIMARY KEY, codigo VARCHAR(80) UNIQUE NOT NULL, nombre VARCHAR(150) NOT NULL,
            descripcion TEXT, url_base VARCHAR(500) NOT NULL, tipo VARCHAR(30) DEFAULT 'REST',
            activo BOOLEAN DEFAULT TRUE, created_by BIGINT NULL, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement("CREATE TABLE integraciones.servicios (
            id BIGSERIAL PRIMARY KEY, proveedor_id BIGINT NOT NULL REFERENCES integraciones.proveedores(id),
            codigo VARCHAR(100) UNIQUE NOT NULL, nombre VARCHAR(150) NOT NULL, endpoint VARCHAR(500) NOT NULL,
            metodo VARCHAR(10) DEFAULT 'POST', tipo_autenticacion VARCHAR(40) DEFAULT 'OAUTH2_CLIENT_CREDENTIALS',
            headers JSONB DEFAULT '{}', timeout_segundos INTEGER DEFAULT 25, reintentos INTEGER DEFAULT 3,
            configuracion JSONB DEFAULT '{}', activo BOOLEAN DEFAULT TRUE, created_by BIGINT NULL,
            created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement('CREATE TABLE integraciones.credenciales (
            id BIGSERIAL PRIMARY KEY, proveedor_id BIGINT NOT NULL REFERENCES integraciones.proveedores(id),
            nombre VARCHAR(150) NOT NULL, tenant_env VARCHAR(120), client_id_env VARCHAR(120),
            client_secret_env VARCHAR(120), scope_env VARCHAR(120), token_url VARCHAR(500),
            activo BOOLEAN DEFAULT TRUE, created_by BIGINT NULL, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )');

        DB::statement("CREATE TABLE notificaciones.plantillas (
            id BIGSERIAL PRIMARY KEY, codigo VARCHAR(100) UNIQUE NOT NULL, nombre VARCHAR(160) NOT NULL,
            asunto VARCHAR(255) NOT NULL, cuerpo_html TEXT NOT NULL, variables JSONB DEFAULT '[]',
            activo BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement("CREATE TABLE notificaciones.notificaciones (
            id BIGSERIAL PRIMARY KEY, usuario_id BIGINT NOT NULL REFERENCES seguridad.users(id) ON DELETE CASCADE,
            plantilla_id BIGINT NULL REFERENCES notificaciones.plantillas(id) ON DELETE SET NULL,
            tipo VARCHAR(80) NOT NULL DEFAULT 'INVITACION_USUARIO', estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
            correo_destino VARCHAR(255) NOT NULL, lote_uuid UUID NULL, solicitado_por BIGINT NULL,
            encolado_at TIMESTAMP NULL, enviado_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )");
        DB::statement('CREATE INDEX notificaciones_usuario_estado_idx ON notificaciones.notificaciones(usuario_id, estado)');
        DB::statement('CREATE TABLE notificaciones.intentos (
            id BIGSERIAL PRIMARY KEY, notificacion_id BIGINT NOT NULL REFERENCES notificaciones.notificaciones(id) ON DELETE CASCADE,
            numero_intento INTEGER NOT NULL, estado VARCHAR(30) NOT NULL, proveedor VARCHAR(80), http_status INTEGER,
            correlation_id VARCHAR(255), respuesta_sanitizada JSONB, codigo_error VARCHAR(100), mensaje_error TEXT,
            iniciado_at TIMESTAMP NULL, finalizado_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(notificacion_id, numero_intento)
        )');
        DB::statement('CREATE TABLE notificaciones.tokens_activacion (
            id BIGSERIAL PRIMARY KEY, usuario_id BIGINT NOT NULL REFERENCES seguridad.users(id) ON DELETE CASCADE,
            token_hash VARCHAR(64) UNIQUE NOT NULL, expires_at TIMESTAMP NOT NULL, usado_at TIMESTAMP NULL,
            invalidado_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
        )');

        $proveedorId = DB::table('integraciones.proveedores')->insertGetId([
            'codigo' => 'MICROSOFT_GRAPH', 'nombre' => 'Microsoft Graph',
            'descripcion' => 'Proveedor institucional para envío de correos.',
            'url_base' => 'https://graph.microsoft.com', 'activo' => true,
        ]);
        DB::table('integraciones.servicios')->insert([
            'proveedor_id' => $proveedorId, 'codigo' => 'CORREO_ENVIAR', 'nombre' => 'Enviar correo',
            'endpoint' => '/v1.0/users/{sender}/sendMail', 'metodo' => 'POST', 'activo' => true,
        ]);
        DB::table('integraciones.credenciales')->insert([
            'proveedor_id' => $proveedorId, 'nombre' => 'Microsoft Graph - Correo',
            'tenant_env' => 'AZURE_TENANT_ID', 'client_id_env' => 'AZURE_CLIENT_ID_OUTLOOK_GRAPH',
            'client_secret_env' => 'AZURE_CLIENT_SECRET_OUTLOOK_GRAPH', 'scope_env' => 'AZURE_SCOPE',
            'token_url' => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token', 'activo' => true,
        ]);
        DB::table('notificaciones.plantillas')->insert([
            'codigo' => 'INVITACION_USUARIO', 'nombre' => 'Invitación de acceso al sistema',
            'asunto' => 'Activa tu acceso a {{nombre_sistema}}',
            'cuerpo_html' => '<p>Hola <strong>{{nombre_usuario}}</strong>,</p><p>Tu cuenta ha sido creada.</p><p><a href="{{url_activacion}}">Establecer mi contraseña</a></p><p>Este enlace vence el {{fecha_expiracion}}.</p>',
            'variables' => json_encode(['nombre_sistema', 'nombre_usuario', 'url_activacion', 'fecha_expiracion']),
            'activo' => true,
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS notificaciones CASCADE');
        DB::statement('DROP SCHEMA IF EXISTS integraciones CASCADE');
    }
};
