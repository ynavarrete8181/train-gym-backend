<?php

namespace App\Services\Gimnasio;

use App\Services\CuentasCobrar\CarteraServicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emite las ventas de cuotas programadas sin duplicarlas.
 * Reutiliza Ventas/Cartera; no registra pagos ni emite facturas fiscales.
 */
class EmisionCuotasServicio
{
    public function __construct(
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

                // Scheduler sin usuario interactivo: inserción de SISTEMA
                // (igual que el cobro programado heredado), sin suplantar un cajero.
                $estadoId = DB::table('configuracion.estados_catalogo')
                    ->where('entidad', 'VENTA')->where('valor_interno', 'PENDIENTE')
                    ->where('activo', true)->value('id');
                $numeroVenta = 'VENTA-CUOTA-' . $cuota->id;
                $ventaId = DB::table('ventas.ventas')->insertGetId([
                    'cliente_id' => $membresia->deportista_id,
                    'membresia_id' => $membresia->id,
                    'caja_id' => null,
                    'usuario_id' => null,
                    'responsable_comercial_id' => null,
                    'numero' => $numeroVenta,
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
                    'estado_id' => $estadoId,
                    'fecha_venta' => now(),
                    'observaciones' => 'Venta automática del calendario contractual.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('ventas.venta_detalles')->insert([
                    'venta_id' => $ventaId,
                    'producto_id' => null,
                    'tipo_item' => 'MEMBRESIA',
                    'referencia_id' => $plan->id,
                    'descripcion' => $plan->nombre . ' - Cuota ' . $cuota->numero_cuota,
                    'cantidad' => 1,
                    'precio_unitario' => $cuota->monto,
                    'total_linea' => $cuota->monto,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('ventas.comprobantes')->insert([
                    'venta_id' => $ventaId,
                    'pago_id' => null,
                    'tipo_comprobante' => 'RECIBO',
                    'numero' => 'COMP-CUOTA-' . $cuota->id,
                    'estado' => 'BORRADOR',
                    'subtotal' => $cuota->monto,
                    'impuesto' => 0,
                    'total' => $cuota->monto,
                    'emitido_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('membresias.membresia_cuotas')->where('id', $cuota->id)->update([
                    'venta_id' => $ventaId,
                    'estado' => 'EMITIDA',
                    'emitida_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('membresias.membresia_periodos')->where('id', $periodo->id)->update([
                    'venta_id' => $ventaId,
                    'estado' => 'PENDIENTE_PAGO',
                    'updated_at' => now(),
                ]);

                // guardarVenta crea la cuenta; reemplazamos su fecha provisional
                // por el vencimiento contractual independiente de dias_gracia.
                $this->cartera->sincronizarVenta((int) $ventaId);
                DB::table('cuentas_cobrar.cuentas')->where('venta_id', $ventaId)
                    ->update(['fecha_vencimiento' => $cuota->fecha_vencimiento, 'updated_at' => now()]);

                $emitidas++;
            });
        }

        return ['cuotas_procesadas' => $ids->count(), 'cuotas_emitidas' => $emitidas, 'cuotas_omitidas' => $omitidas];
    }
}
