<?php

namespace App\Services\Gimnasio;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CobroProgramadoMembresiaServicio
{
    public function __construct(private readonly MembresiaServicio $membresias)
    {
    }

    public function procesar(?string $fecha = null): array
    {
        $hoy = Carbon::parse($fecha ?: now()->toDateString())->startOfDay();
        $creadas = 0;
        $omitidas = 0;

        $ids = DB::table('membresias.membresias')
            ->where('generar_venta_automatica', true)
            ->whereNotNull('dia_pago')
            ->whereNotNull('proxima_fecha_cobro')
            ->whereDate('proxima_fecha_cobro', '<=', $hoy->toDateString())
            ->whereNotIn('estado', ['CANCELADA'])
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $hoy, &$creadas, &$omitidas): void {
                $membresia = DB::table('membresias.membresias')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $membresia || ! $membresia->generar_venta_automatica || ! $membresia->dia_pago) {
                    $omitidas++;
                    return;
                }

                $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();
                if (! $plan) {
                    $omitidas++;
                    return;
                }

                $fechaCobro = Carbon::parse($membresia->proxima_fecha_cobro)->startOfDay();

                $periodo = DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $id)
                    ->orderByDesc('numero_periodo')
                    ->lockForUpdate()
                    ->first();

                if ($periodo && $fechaCobro->gt(Carbon::parse($periodo->fecha_fin)) && ($plan->renovable ?? false)) {
                    $periodo = $this->membresias->crearSiguientePeriodo((int) $id);
                    $membresia = DB::table('membresias.membresias')->where('id', $id)->first();
                }

                if (! $periodo) {
                    $omitidas++;
                    return;
                }

                if (! $periodo->venta_id) {
                    $ventaExistente = DB::table('ventas.ventas')
                        ->where('membresia_id', $id)
                        ->where('estado', '!=', 'ANULADA')
                        ->whereDate('fecha_venta', $fechaCobro->toDateString())
                        ->first();

                    if ($ventaExistente) {
                        $ventaId = (int) $ventaExistente->id;
                    } else {
                        $estadoId = DB::table('configuracion.estados_catalogo')
                            ->where('entidad', 'VENTA')
                            ->where('valor_interno', 'PENDIENTE')
                            ->where('activo', true)
                            ->value('id');

                        $numero = 'VENTA-' . now()->format('YmdHis') . '-' . random_int(100, 999);
                        $concepto = $plan->nombre
                            . ' - ' . $membresia->codigo_contrato
                            . ' - Cobro programado '
                            . $fechaCobro->format('Y-m-d');

                        $ventaId = DB::table('ventas.ventas')->insertGetId([
                            'cliente_id' => $membresia->deportista_id,
                            'membresia_id' => $id,
                            'caja_id' => null,
                            'usuario_id' => null,
                            'responsable_comercial_id' => null,
                            'numero' => $numero,
                            'origen_tipo' => 'MEMBRESIA_RENOVACION_AUTOMATICA',
                            'origen_id' => $id,
                            'generado_por_tipo' => 'SISTEMA',
                            'tipo_venta' => ($plan->tipo_producto ?? 'MEMBRESIA') === 'PASE_DIARIO' ? 'SERVICIO' : 'MEMBRESIA',
                            'concepto' => $concepto,
                            'subtotal' => $periodo->precio,
                            'descuento' => 0,
                            'impuesto' => 0,
                            'total' => $periodo->precio,
                            'estado' => 'PENDIENTE',
                            'estado_id' => $estadoId,
                            'fecha_venta' => $fechaCobro->copy()->setTime(8, 0),
                            'observaciones' => 'Venta generada automáticamente según el día habitual de pago de la membresía.',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('ventas.venta_detalles')->insert([
                            'venta_id' => $ventaId,
                            'producto_id' => null,
                            'tipo_item' => 'MEMBRESIA',
                            'referencia_id' => $plan->id,
                            'descripcion' => $plan->nombre,
                            'cantidad' => 1,
                            'precio_unitario' => $periodo->precio,
                            'total_linea' => $periodo->precio,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('ventas.comprobantes')->insert([
                            'venta_id' => $ventaId,
                            'pago_id' => null,
                            'tipo_comprobante' => 'RECIBO',
                            'numero' => 'COMP-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                            'estado' => 'BORRADOR',
                            'subtotal' => $periodo->precio,
                            'impuesto' => 0,
                            'total' => $periodo->precio,
                            'emitido_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $creadas++;
                    }

                    DB::table('membresias.membresia_periodos')
                        ->where('id', $periodo->id)
                        ->update([
                            'venta_id' => $ventaId,
                            'updated_at' => now(),
                        ]);
                } else {
                    $omitidas++;
                }

                $siguiente = $this->siguienteFechaMensual($fechaCobro, (int) $membresia->dia_pago);
                DB::table('membresias.membresias')
                    ->where('id', $id)
                    ->update([
                        'proxima_fecha_cobro' => ($plan->renovable ?? false) ? $siguiente->toDateString() : null,
                        'updated_at' => now(),
                    ]);
            });
        }

        return [
            'procesadas' => $ids->count(),
            'ventas_creadas' => $creadas,
            'omitidas' => $omitidas,
            'fecha' => $hoy->toDateString(),
        ];
    }

    private function siguienteFechaMensual(Carbon $fechaActual, int $diaPago): Carbon
    {
        $base = $fechaActual->copy()->addMonthNoOverflow()->startOfMonth();
        $dia = min($diaPago, $base->daysInMonth);
        return $base->day($dia);
    }
}
