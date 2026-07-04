<?php

use Illuminate\Support\Facades\DB;

try {
    DB::statement('CREATE SCHEMA IF NOT EXISTS ventas;');

    DB::statement('
        CREATE TABLE IF NOT EXISTS ventas.ventas (
            id BIGSERIAL PRIMARY KEY,
            sede_id BIGINT NOT NULL,
            cliente_id BIGINT NULL,
            vendedor_id BIGINT NOT NULL,
            total DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            estado SMALLINT NOT NULL DEFAULT 1, -- 1: Completada, 0: Anulada
            fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT NOT NULL,
            updated_by BIGINT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ');

    DB::statement('
        CREATE TABLE IF NOT EXISTS ventas.venta_detalles (
            id BIGSERIAL PRIMARY KEY,
            venta_id BIGINT NOT NULL REFERENCES ventas.ventas(id) ON DELETE CASCADE,
            producto_id BIGINT NOT NULL,
            cantidad INT NOT NULL DEFAULT 1,
            precio_unitario DECIMAL(10, 2) NOT NULL,
            subtotal DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ');

    echo "Ventas schema and tables created successfully.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
