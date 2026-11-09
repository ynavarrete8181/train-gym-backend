<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa 2026_11_07_000000_unificar_hora_ecuador: esa migración no incluyó
 * ventas.fecha_venta ni pagos.fecha_pago (no terminan en _at). En producción esas
 * columnas quedaron en UTC, 5 horas por delante de su created_at ya convertido.
 *
 * Solo resta 5 horas cuando la diferencia con created_at está entre 4h55 y 5h05:
 * en desarrollo no cambia nada y volver a ejecutarla tampoco.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            foreach (['ventas.ventas' => 'fecha_venta', 'ventas.pagos' => 'fecha_pago'] as $tabla => $columna) {
                $filas = DB::update("
                    UPDATE {$tabla}
                    SET {$columna} = {$columna} - INTERVAL '5 hours'
                    WHERE {$columna} IS NOT NULL AND created_at IS NOT NULL
                      AND {$columna} - created_at BETWEEN INTERVAL '4 hours 55 minutes' AND INTERVAL '5 hours 5 minutes'
                ");
                echo "  {$tabla}.{$columna}: {$filas} filas convertidas a hora de Ecuador".PHP_EOL;
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Esta conversión se revierte restaurando el respaldo de la base.');
    }
};
