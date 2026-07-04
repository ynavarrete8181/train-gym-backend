<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE ventas.ventas
    ADD COLUMN IF NOT EXISTS tipo_venta VARCHAR(30) NOT NULL DEFAULT 'CONSUMO',
    ADD COLUMN IF NOT EXISTS estado_pago VARCHAR(30) NOT NULL DEFAULT 'PAGADO',
    ADD COLUMN IF NOT EXISTS saldo_pendiente NUMERIC(12, 2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS fecha_consumo DATE NOT NULL DEFAULT CURRENT_DATE,
    ADD COLUMN IF NOT EXISTS membresia_id BIGINT,
    ADD COLUMN IF NOT EXISTS metadata JSONB;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ventas_membresia_id'
    ) THEN
        ALTER TABLE ventas.ventas
            ADD CONSTRAINT fk_ventas_membresia_id
            FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_ventas_persona_estado_pago
    ON ventas.ventas (persona_id, estado_pago, fecha_consumo DESC);

CREATE INDEX IF NOT EXISTS idx_ventas_tipo_venta
    ON ventas.ventas (tipo_venta, fecha_consumo DESC);

UPDATE ventas.ventas
SET
    tipo_venta = COALESCE(NULLIF(tipo_venta, ''), 'CONSUMO'),
    estado_pago = CASE
        WHEN COALESCE(forma_pago, '') = '' THEN 'PENDIENTE'
        ELSE 'PAGADO'
    END,
    saldo_pendiente = CASE
        WHEN COALESCE(forma_pago, '') = '' THEN COALESCE(total, 0)
        ELSE 0
    END,
    fecha_consumo = COALESCE(fecha_consumo, DATE(created_at), CURRENT_DATE),
    metadata = COALESCE(metadata, '{}'::jsonb)
WHERE TRUE;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_ventas_tipo_venta;
DROP INDEX IF EXISTS idx_ventas_persona_estado_pago;

ALTER TABLE ventas.ventas
    DROP CONSTRAINT IF EXISTS fk_ventas_membresia_id,
    DROP COLUMN IF EXISTS metadata,
    DROP COLUMN IF EXISTS membresia_id,
    DROP COLUMN IF EXISTS fecha_consumo,
    DROP COLUMN IF EXISTS saldo_pendiente,
    DROP COLUMN IF EXISTS estado_pago,
    DROP COLUMN IF EXISTS tipo_venta;
SQL);
    }
};
