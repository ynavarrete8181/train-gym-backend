<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS ventas.punto_venta_borradores (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES seguridad.usuarios(id) ON DELETE CASCADE,
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
    persona_id BIGINT REFERENCES core.personas(id),
    membresia_id BIGINT REFERENCES socios.membresias(id),
    referencia VARCHAR(100) NOT NULL,
    observacion TEXT,
    forma_pago VARCHAR(30),
    estado_pago VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
    tipo_venta VARCHAR(30) NOT NULL DEFAULT 'CONSUMO',
    subtotal NUMERIC(12, 2) NOT NULL DEFAULT 0,
    iva NUMERIC(12, 2) NOT NULL DEFAULT 0,
    total NUMERIC(12, 2) NOT NULL DEFAULT 0,
    items JSONB NOT NULL DEFAULT '[]'::jsonb,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    venta_id BIGINT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pos_borradores_usuario_estado
    ON ventas.punto_venta_borradores (usuario_id, estado_pago, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_pos_borradores_persona
    ON ventas.punto_venta_borradores (persona_id, updated_at DESC);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_pos_borradores_persona;
DROP INDEX IF EXISTS idx_pos_borradores_usuario_estado;
DROP TABLE IF EXISTS ventas.punto_venta_borradores;
SQL);
    }
};
