<?php

namespace App\Services\Gimnasio;

use App\Services\CuentasCobrar\CarteraServicio;
use App\Services\Ventas\VentaServicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emite las ventas de cuotas programadas sin duplicarlas.
 * Reutiliza Ventas/Cartera; no registra pagos ni emite facturas fiscales.
 */
class EmisionCuotasServicio
{
    public function __construct(
        private readonly VentaServicio $ventas,
        private readonly CarteraServicio $cartera,
    ) {
    }

    public function procesar(?string $fecha = null): array
    {
        $hoy = Carbon::parse($fecha ?: now()->toDateString())->toDateString();
        $ids = DB::table('membresias.membresia_cuotas')
            ->where('estado', 'PROGRAMADA')
            ->where('fecha_programada_emision', '<=', $hoy)
            ->orderBy('id')->pluck('id');

        $emitidas = 0;
        $omitidas = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$emitidas, &$omitidas): void {
                $cuota = DB::table('membresias.membresia_cuotas')
                    ->where('id', $id)->lockForUpdate()->first();

                if (! $cuota || $cuota->estado !== 'PROGRAMADA' || $cuota->venta_id) {
                    $omitidas++;
                    return;
                }

                $membresia = DB::table('membresias.membresias')
                    ->where('id', $cuota->membresia_id)->first();

                if (! $membresia || $membresia->estado === 'CANCELADA') {
                    $omitidas++;
                    return;
                }

                $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();
                $periodo = $cuota->periodo_id
                    ? DB::table('membresias.membresia_periodos')->where('id', $cuota->periodo_id)->first()
                    : null;

                if (! $plan || ! $periodo || $periodo->venta_id !== null) {
                    $omitidas++;
                    return;
                }

                $venta = $this->ventas->guardarVenta([
                    'cliente_id' => $membresia->deportista_id,
                    'membresia_id' => $membresia->id,
                    'caja_id' => null,
                    'origen_tipo' => 'MEMBRESIA_CUOTA_PROGRAMADA',
                    'origen_id' => $cuota->id,
                    'generado_por_tipo' => 'SISTEMA',
                    'tipo_venta' => 'MEMBRESIA',
                    'concepto' => $plan->nombre . ' - ' . $membresia->codigo_contrato
                        . ' - Cuota ' . $cuota->numero_cuota,
                    'subtotal' => $cuota->monto,
                    'descuento' => 0,
                    'impuesto' => 0,
                    'total' => $cuota->monto,
                    'estado' => 'PENDIENTE',
                    'observaciones' => 'Venta generada a partir del calendario contractual de cuotas.',
                    'detalle' => [
                        'tipo_item' => 'MEMBRESIA',
                        'referencia_id' => $plan->id,
                        'descripcion' => $plan->nombre . ' - Cuota ' . $cuota->numero_cuota,
                        'cantidad' => 1,
                        'precio_unitario' => $cuota->monto,
                        'total_linea' => $cuota->monto,
                    ],
                ]);

                DB::table('membresias.membresia_cuotas')->where('id', $cuota->id)->update([
                    'venta_id' => $venta->id,
                    'estado' => 'EMITIDA',
                    'emitida_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('membresias.membresia_periodos')->where('id', $periodo->id)->update([
                    'venta_id' => $venta->id,
                    'estado' => 'PENDIENTE_PAGO',
                    'updated_at' => now(),
                ]);

                // guardarVenta crea la cuenta; reemplazamos su fecha provisional
                // por el vencimiento contractual independiente de dias_gracia.
                $this->cartera->sincronizarVenta((int) $venta->id);
                DB::table('cuentas_cobrar.cuentas')->where('venta_id', $venta->id)
                    ->update(['fecha_vencimiento' => $cuota->fecha_vencimiento, 'updated_at' => now()]);

                $emitidas++;
            });
        }

        return ['cuotas_procesadas' => $ids->count(), 'cuotas_emitidas' => $emitidas, 'cuotas_omitidas' => $omitidas];
    }
}
