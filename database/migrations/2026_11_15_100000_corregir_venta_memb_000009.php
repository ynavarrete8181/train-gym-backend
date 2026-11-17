<?php

use App\Services\Gimnasio\DescuentoDeportistaServicio;
use App\Services\Ventas\VentaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MEMB-000009 (Rocio Roldan): contrato a $40 con 25 % de descuento desde el 02/11 → debía pagar $30.
 * La venta se generó sin descuento (se evaluó el 01/11, antes de que empezara) y el cobro del 13/11
 * a las 09:37, anterior a la corrección de caja, la repreció a la tarifa del día ($60). Pagó $30 por
 * transferencia y quedó con saldo falso de $30 y la membresía en PENDIENTE_PAGO.
 * Se deja la venta en $40 − $10 = $30 pagada, se activa la membresía y queda en el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        $m = DB::table('membresias.membresias')->where('codigo_contrato', 'MEMB-000009')->first();
        if (! $m) {
            return;
        }
        $periodo = DB::table('membresias.membresia_periodos')->where('membresia_id', $m->id)->where('numero_periodo', 1)->first();
        $venta = $periodo?->venta_id ? DB::table('ventas.ventas')->where('id', $periodo->venta_id)->first() : null;
        // Solo si sigue exactamente como quedó por el error.
        if (! $venta || abs((float) $venta->subtotal - 60) > 0.009 || (float) $venta->descuento > 0 || $venta->estado !== 'PARCIAL') {
            return;
        }
        $pagado = round((float) DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->sum('monto'), 2);

        DB::transaction(function () use ($m, $periodo, $venta, $pagado): void {
            $precio = round((float) $periodo->precio, 2); // 40.00 pactado
            $beneficio = app(DescuentoDeportistaServicio::class)->resolver(
                (int) $m->deportista_id, (int) $m->plan_id, $m->modalidad_id ? (int) $m->modalidad_id : null,
                substr((string) $periodo->fecha_inicio, 0, 10), $precio, $m->sede_id ? (int) $m->sede_id : null
            );
            $descuento = round((float) $beneficio['importe_descuento'], 2);
            $total = round($precio - $descuento, 2);
            $nota = "Corrección: precio pactado $" . number_format($precio, 2) . ' (no la tarifa de $60) con descuento de $'
                . number_format($descuento, 2) . ' vigente al inicio del período; total $' . number_format($total, 2) . '.';

            DB::table('ventas.venta_detalles')->where('venta_id', $venta->id)->where('tipo_item', 'MEMBRESIA')
                ->update(['precio_unitario' => $precio, 'total_linea' => $precio, 'updated_at' => now()]);
            DB::table('ventas.ventas')->where('id', $venta->id)->update([
                'subtotal' => $precio, 'descuento' => $descuento, 'total' => $total,
                'observaciones' => trim(($venta->observaciones ?? '') . ' ' . $nota), 'updated_at' => now(),
            ]);
            DB::table('membresias.membresia_periodos')->where('id', $periodo->id)
                ->update(['descuento_id' => $beneficio['descuento_id'], 'descuento_importe' => $descuento, 'updated_at' => now()]);
            DB::table('membresias.membresia_cuotas')->where('venta_id', $venta->id)->update(['monto' => $total, 'updated_at' => now()]);

            // Estado de la venta, acceso de la membresía, período y cartera con la regla normal de cobro.
            app(VentaServicio::class)->actualizarEstadoVenta((int) $venta->id, null);
            $pagada = DB::table('ventas.ventas')->where('id', $venta->id)->value('estado') === 'PAGADA';
            DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update([
                'subtotal' => $precio, 'total' => $total,
                'estado' => $pagada ? 'EMITIDO' : 'BORRADOR', 'emitido_at' => $pagada ? now() : null, 'updated_at' => now(),
            ]);

            DB::table('membresias.membresia_movimientos')->insert([
                'membresia_id' => $m->id, 'tipo' => 'VENTAS_RECALCULADAS', 'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now()->toDateString(),
                'motivo' => "Corrección de la venta {$venta->numero} del período 1: al cobrar el 13/11 se tomó la tarifa del día ($60) sin el descuento del 25 %. "
                    . "Queda $" . number_format($precio, 2) . ' − $' . number_format($descuento, 2) . ' = $' . number_format($total, 2)
                    . ', pagado $' . number_format($pagado, 2) . ' por transferencia (COBRO-20261113-0003). '
                    . ($pagada ? 'Venta pagada y membresía activa.' : 'Saldo pendiente.'),
                'usuario_id' => null, 'autorizado_por' => null,
                'detalle' => json_encode(['actualizadas' => [['periodo' => 1, 'venta' => $venta->numero,
                    'descuento_antes' => (float) $venta->descuento, 'descuento_despues' => $descuento,
                    'total_antes' => (float) $venta->total, 'total_despues' => $total]], 'cobradas' => []], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Sin reversión automática: la corrección queda en el historial del contrato.
    }
};
