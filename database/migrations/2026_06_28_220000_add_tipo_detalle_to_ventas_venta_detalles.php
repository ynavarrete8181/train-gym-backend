<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE ventas.venta_detalles
    ALTER COLUMN producto_id DROP NOT NULL,
    ADD COLUMN IF NOT EXISTS membresia_id BIGINT,
    ADD COLUMN IF NOT EXISTS tipo_detalle VARCHAR(30) NOT NULL DEFAULT 'PRODUCTO',
    ADD COLUMN IF NOT EXISTS descripcion TEXT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ventas_venta_detalles_membresia_id'
    ) THEN
        ALTER TABLE ventas.venta_detalles
            ADD CONSTRAINT fk_ventas_venta_detalles_membresia_id
            FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);
    END IF;
END $$;

UPDATE ventas.venta_detalles
SET
    tipo_detalle = CASE
        WHEN membresia_id IS NOT NULL THEN 'MEMBRESIA'
        ELSE COALESCE(NULLIF(tipo_detalle, ''), 'PRODUCTO')
    END
WHERE TRUE;

CREATE INDEX IF NOT EXISTS idx_ventas_venta_detalles_tipo_detalle
    ON ventas.venta_detalles (tipo_detalle);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_ventas_venta_detalles_tipo_detalle;

ALTER TABLE ventas.venta_detalles
    DROP CONSTRAINT IF EXISTS fk_ventas_venta_detalles_membresia_id,
    DROP COLUMN IF EXISTS descripcion,
    DROP COLUMN IF EXISTS tipo_detalle,
    DROP COLUMN IF EXISTS membresia_id;

ALTER TABLE ventas.venta_detalles
    ALTER COLUMN producto_id SET NOT NULL;
SQL);
    }
};
