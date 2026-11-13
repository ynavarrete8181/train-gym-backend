<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El primer cambio de precio (MEMB-000016, 13/11/2026) se guardó en el historial del contrato y en
 * los movimientos, y aparecía dos veces. Se quita la copia del historial del contrato; el
 * movimiento CAMBIO_PRECIO con su motivo se conserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('membresias.membresias')->where('codigo_contrato', 'MEMB-000016')->value('id');
        if (! $id) return;
        if (! DB::table('membresias.membresia_movimientos')->where('membresia_id', $id)->where('tipo', 'CAMBIO_PRECIO')->exists()) return;
        DB::table('membresias.membresia_condiciones_historial')
            ->where('membresia_id', $id)->where('accion', 'CAMBIO_PRECIO')->delete();
    }

    public function down(): void
    {
    }
};
