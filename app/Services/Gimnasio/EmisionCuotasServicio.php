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
        $hoy = Carbon::parse($fecha ?: now('America/Guayaquil')->toDateString())->toDateString();
        $ids = DB::table('membresias.membresia_cuotas')
            ->where('estado', 'PROGRAMADA')
            ->where('fecha_programada_emision', '<=', $hoy)
            ->orderBy('id')->pluck('id');

        $emitidas = 0;
        $omitidas = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $hoy, &$emitidas, &$omitidas): void {
                // Bloquear siempre contrato ANTES de cuota, igual que el servicio
                // de congelación. Evita carreras y bloqueos inversos con finanzas.
                $referencia = DB::table('membresias.membresia_cuotas')->where('id', $id)
                    ->first(['membresia_id']);
                if (! $referencia) {
                    $omitidas++;
                    return;
                }
                $membresia = DB::table('membresias.membresias')
                    ->where('id', $referencia->membresia_id)->lockForUpdate()->first();
                $cuota = DB::table('membresias.membresia_cuotas')
                    ->where('id', $id)->lockForUpdate()->first();

                // Un freeze/reactivación puede haber movido fechas después de
                // seleccionar IDs; nunca emitir por la fecha antigua.
                if (! $cuota || $cuota->estado !== 'PROGRAMADA' || $cuota->venta_id
                    || $cuota->fecha_programada_emision > $hoy) {
                    $omitidas++;
                    return;
                }

                if (! $membresia || (int) $cuota->membresia_id !== (int) $membresia->id
                    || in_array($membresia->estado, ['CANCELADA', 'VENCIDA'], true)) {
                    $omitidas++;
                    return;
                }

                // Una suspensión financiera VIGENTE bloquea la emisión incluso
                // cuando el estado del contrato todavía figure como ACTIVA.
                $suspensionFinanciera = DB::table('membresias.membresia_suspensiones')
                    ->where('membresia_id', $membresia->id)
                    ->where('tipo', 'FACTURACION')
                    ->where('estado', 'VIGENTE')
                    ->exists();
                if ($suspensionFinanciera) {
                    $omitidas++;
                    return;
                }

                // TIEMPO conserva el calendario pactado. No emitir contra una
                // congelación heredada sin suspensión VIGENTE trazable.
                if ($membresia->estado === 'CONGELADA'
                    && ! DB::table('membresias.membresia_suspensiones')
                        ->where('membresia_id', $membresia->id)
                        ->where('tipo', 'TIEMPO')
                        ->where('estado', 'VIGENTE')
                        ->exists()) {
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
                    'fecha_venta' => now('America/Guayaquil'),
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

                // Un período R-E-N-O-V-A-D-O exige su propio pago. La
                // extensión contractual no concede acceso gratuito cuando
                // comienza el nuevo período.
                if (($periodo->renovacion_manual ?? false)
                    && substr((string) $periodo->fecha_inicio, 0, 10) <= $hoy
                    && $membresia->estado === 'ACTIVA') {
                    $estadoPendiente = DB::table('configuracion.estados_catalogo')
                        ->where('entidad', 'MEMBRESIA')
                        ->where('valor_interno', 'PENDIENTE_PAGO')
                        ->where('activo', true)->value('id');
                    if (! $estadoPendiente) {
                        throw new \RuntimeException('Falta el estado PENDIENTE_PAGO para membresías.');
                    }
                    DB::table('membresias.membresias')->where('id', $membresia->id)->update([
                        'estado' => 'PENDIENTE_PAGO',
                        'estado_id' => $estadoPendiente,
                        'updated_at' => now(),
                    ]);
                }

                // Sincronizar Cartera después de enlazar la cuota a la venta:
                // así el vencimiento proviene de la cuota, no de dias_gracia.
                $this->cartera->sincronizarVenta((int) $ventaId);
                DB::table('cuentas_cobrar.cuentas')->where('venta_id', $ventaId)
                    ->update(['fecha_vencimiento' => $cuota->fecha_vencimiento, 'updated_at' => now()]);

                $emitidas++;
            });
        }

        return ['cuotas_procesadas' => $ids->count(), 'cuotas_emitidas' => $emitidas, 'cuotas_omitidas' => $omitidas];
    }
}
