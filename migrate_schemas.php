<?php

use Illuminate\Support\Facades\DB;

try {
    DB::statement('CREATE SCHEMA IF NOT EXISTS inventario;');
    DB::statement('CREATE SCHEMA IF NOT EXISTS auditoria;');

    // Move auditoria
    DB::statement('ALTER TABLE train_gimnasio.aud_cambios SET SCHEMA auditoria;');

    // Move inventario
    $tables = [
        'categorias_producto',
        'productos',
        'producto_precios',
        'producto_lotes',
        'producto_stock_sede',
        'movimientos_inventario',
        'transferencias_inventario',
        'transferencia_detalle'
    ];

    foreach ($tables as $table) {
        DB::statement("ALTER TABLE train_gimnasio.{$table} SET SCHEMA inventario;");
        echo "Moved {$table} to inventario schema.\n";
    }

    echo "Migration completed successfully.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
