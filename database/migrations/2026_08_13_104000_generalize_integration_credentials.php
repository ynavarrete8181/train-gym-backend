<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE integraciones.credenciales ADD COLUMN codigo VARCHAR(100), ADD COLUMN tipo_autenticacion VARCHAR(40) NOT NULL DEFAULT 'OAUTH2_CLIENT_CREDENTIALS', ADD COLUMN datos_cifrados TEXT, ADD COLUMN configuracion JSONB NOT NULL DEFAULT '{}', ADD COLUMN ultima_prueba_estado VARCHAR(30), ADD COLUMN ultima_prueba_mensaje VARCHAR(500), ADD COLUMN ultima_prueba_at TIMESTAMP, ADD COLUMN updated_by BIGINT");
        DB::statement("UPDATE integraciones.credenciales SET codigo = 'MICROSOFT_GRAPH_CORREO' WHERE codigo IS NULL");
        DB::statement('ALTER TABLE integraciones.credenciales ALTER COLUMN codigo SET NOT NULL');
        DB::statement('ALTER TABLE integraciones.credenciales ADD CONSTRAINT credenciales_codigo_unique UNIQUE (codigo)');
        DB::statement('ALTER TABLE integraciones.servicios ADD COLUMN credencial_id BIGINT NULL REFERENCES integraciones.credenciales(id) ON DELETE SET NULL');
        DB::statement("UPDATE integraciones.servicios s SET credencial_id = c.id FROM integraciones.credenciales c WHERE s.proveedor_id = c.proveedor_id AND s.codigo = 'CORREO_ENVIAR'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE integraciones.servicios DROP COLUMN IF EXISTS credencial_id');
        DB::statement('ALTER TABLE integraciones.credenciales DROP CONSTRAINT IF EXISTS credenciales_codigo_unique');
        DB::statement('ALTER TABLE integraciones.credenciales DROP COLUMN IF EXISTS codigo, DROP COLUMN IF EXISTS tipo_autenticacion, DROP COLUMN IF EXISTS datos_cifrados, DROP COLUMN IF EXISTS configuracion, DROP COLUMN IF EXISTS ultima_prueba_estado, DROP COLUMN IF EXISTS ultima_prueba_mensaje, DROP COLUMN IF EXISTS ultima_prueba_at, DROP COLUMN IF EXISTS updated_by');
    }
};
