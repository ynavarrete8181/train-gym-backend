<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE ventas.ventas
    ADD COLUMN IF NOT EXISTS estado_devolucion VARCHAR(30) NOT NULL DEFAULT 'SIN_DEVOLUCION',
    ADD COLUMN IF NOT EXISTS monto_devuelto NUMERIC(12, 2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS anulada_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS anulada_by BIGINT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ventas_anulada_by'
    ) THEN
        ALTER TABLE ventas.ventas
            ADD CONSTRAINT fk_ventas_anulada_by
            FOREIGN KEY (anulada_by) REFERENCES seguridad.usuarios(id);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS ventas.devoluciones (
    id BIGSERIAL PRIMARY KEY,
    venta_id BIGINT NOT NULL REFERENCES ventas.ventas(id),
    tipo VARCHAR(30) NOT NULL,
    motivo VARCHAR(120) NOT NULL,
    observacion TEXT,
    reintegra_stock BOOLEAN NOT NULL DEFAULT TRUE,
    monto_total NUMERIC(12, 2) NOT NULL DEFAULT 0,
    estado VARCHAR(30) NOT NULL DEFAULT 'APLICADA',
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by BIGINT REFERENCES seguridad.usuarios(id),
    updated_by BIGINT REFERENCES seguridad.usuarios(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ventas.devolucion_detalles (
    id BIGSERIAL PRIMARY KEY,
    devolucion_id BIGINT NOT NULL REFERENCES ventas.devoluciones(id) ON DELETE CASCADE,
    venta_detalle_id BIGINT REFERENCES ventas.venta_detalles(id),
    producto_id BIGINT REFERENCES inventario.productos(id),
    membresia_id BIGINT REFERENCES socios.membresias(id),
    tipo_detalle VARCHAR(30) NOT NULL DEFAULT 'PRODUCTO',
    descripcion TEXT,
    cantidad NUMERIC(12, 2) NOT NULL,
    precio_unitario NUMERIC(12, 2) NOT NULL DEFAULT 0,
    subtotal NUMERIC(12, 2) NOT NULL DEFAULT 0,
    reintegra_stock BOOLEAN NOT NULL DEFAULT TRUE,
    movimiento_inventario_id BIGINT REFERENCES inventario.movimientos_inventario(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ventas_devoluciones_venta
    ON ventas.devoluciones (venta_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ventas_devoluciones_tipo
    ON ventas.devoluciones (tipo, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_ventas_devolucion_detalles_devolucion
    ON ventas.devolucion_detalles (devolucion_id);

CREATE INDEX IF NOT EXISTS idx_ventas_devolucion_detalles_venta_detalle
    ON ventas.devolucion_detalles (venta_detalle_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS ventas.idx_ventas_devolucion_detalles_venta_detalle;
DROP INDEX IF EXISTS ventas.idx_ventas_devolucion_detalles_devolucion;
DROP INDEX IF EXISTS ventas.idx_ventas_devoluciones_tipo;
DROP INDEX IF EXISTS ventas.idx_ventas_devoluciones_venta;

DROP TABLE IF EXISTS ventas.devolucion_detalles;
DROP TABLE IF EXISTS ventas.devoluciones;

ALTER TABLE ventas.ventas
    DROP CONSTRAINT IF EXISTS fk_ventas_anulada_by,
    DROP COLUMN IF EXISTS anulada_by,
    DROP COLUMN IF EXISTS anulada_at,
    DROP COLUMN IF EXISTS monto_devuelto,
    DROP COLUMN IF EXISTS estado_devolucion;
SQL);
    }
};
