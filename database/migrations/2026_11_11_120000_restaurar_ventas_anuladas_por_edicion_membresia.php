<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Editar una membresía de un plan que cobra por caja o por cobro programado
 * anulaba su venta pendiente ("Facturación desactivada desde Membresías.")
 * sin que nadie lo pidiera. Se devuelven a PENDIENTE las ventas anuladas así
 * que no tienen pagos, cuyo plan no genera venta al contratar.
 */
return new class extends Migration
{
    private const MARCA = 'Facturación desactivada desde Membresías.';

    public function up(): void
    {
        $estadoPendienteId = DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'VENTA')
            ->where('valor_interno', 'PENDIENTE')
            ->value('id');

        $ventas = DB::table('ventas.ventas as v')
            ->join('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->where('v.estado', 'ANULADA')
            ->where('v.observaciones', 'like', '%' . self::MARCA . '%')
            ->where('p.generar_venta', false)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('ventas.pagos as pg')
                ->whereColumn('pg.venta_id', 'v.id')
                ->where('pg.estado', 'CONFIRMADO'))
            ->get(['v.id', 'v.observaciones']);

        foreach ($ventas as $venta) {
            $observaciones = trim(str_replace(self::MARCA, '', (string) $venta->observaciones));

            DB::table('ventas.ventas')->where('id', $venta->id)->update([
                'estado' => 'PENDIENTE',
                'estado_id' => $estadoPendienteId,
                'observaciones' => trim($observaciones . ' Restaurada: la edición de la membresía la había anulado por error.'),
                'updated_at' => now(),
            ]);

            DB::table('ventas.comprobantes')
                ->where('venta_id', $venta->id)
                ->where('estado', 'ANULADO')
                ->update(['estado' => 'BORRADOR', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Restauración de datos: no se revierte para no volver a anular deudas reales.
    }
};
