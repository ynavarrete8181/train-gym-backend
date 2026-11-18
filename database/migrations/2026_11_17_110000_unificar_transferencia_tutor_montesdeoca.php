<?php

use App\Services\Auditoria\AuditoriaServicio;
use App\Services\CuentasCobrar\CarteraServicio;
use App\Services\Ventas\TurnoCajaServicio;
use App\Services\Ventas\VentaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La transferencia 77961867 del 13/11/2026 ($200) de Carlos Montesdeoca pagó el período
 * del 04/10 al 08/11 de Carlos ($60), Ema ($70) y Carlitos ($70). El 14/11 se registraron
 * solo los dos primeros, como cobros separados, y faltaron los $70 de Carlitos.
 *
 * Se deja como un solo cobro del tutor: los dos pagos existentes pasan al mismo código de
 * cobro con la referencia de la transferencia y Carlos como pagador, y se registran los $70
 * de Carlitos en el mismo turno de caja en que entró la transferencia. El turno se recalcula.
 * Solo actúa si la base sigue como quedó el 17/11; una segunda ejecución no cambia nada.
 */
return new class extends Migration
{
    private const REFERENCIA = '77961867';

    public function up(): void
    {
        DB::transaction(function (): void {
            $carlos = DB::table('clientes.deportistas')->where('codigo_deportista', '6Y25Y')->first();
            $pagoCarlos = DB::table('ventas.pagos')->where('codigo_cobro', 'COBRO-20261114-0003')->where('estado', 'CONFIRMADO')->first();
            $pagoEma = DB::table('ventas.pagos')->where('codigo_cobro', 'COBRO-20261114-0002')->where('estado', 'CONFIRMADO')->first();
            $memb20 = DB::table('membresias.membresias')->where('codigo_contrato', 'MEMB-000020')->first();
            $memb21 = DB::table('membresias.membresias')->where('codigo_contrato', 'MEMB-000021')->first();
            $p1 = $memb20 ? DB::table('membresias.membresia_periodos')->where('membresia_id', $memb20->id)->where('numero_periodo', 1)->first() : null;
            $p1Ema = $memb21 ? DB::table('membresias.membresia_periodos')->where('membresia_id', $memb21->id)->where('numero_periodo', 1)->first() : null;
            $venta = $p1?->venta_id ? DB::table('ventas.ventas')->where('id', $p1->venta_id)->first() : null;

            if (! $carlos || ! $pagoCarlos || ! $pagoEma || ! $venta || ! $p1Ema
                || abs((float) $pagoCarlos->monto - 60) > 0.001 || abs((float) $pagoEma->monto - 70) > 0.001
                || $venta->estado !== 'PENDIENTE' || abs((float) $venta->total - 70) > 0.001
                || DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->exists()) {
                return;
            }

            $nota = 'Transferencia ' . self::REFERENCIA . ' del 13/11/2026 por $200 de Carlos Montesdeoca (tutor): período del 04/10 al 08/11 de Carlos ($60), Ema ($70) y Carlitos ($70). Unificado en un solo cobro el 17/11/2026.';
            $auditoria = app(AuditoriaServicio::class);

            // Los dos pagos registrados el 14/11 pasan al mismo cobro del tutor.
            foreach ([$pagoCarlos, $pagoEma] as $pago) {
                DB::table('ventas.pagos')->where('id', $pago->id)->update([
                    'operacion_cobro_id' => $pagoEma->operacion_cobro_id,
                    'codigo_cobro' => $pagoEma->codigo_cobro,
                    'referencia' => self::REFERENCIA,
                    'pagador_persona_id' => $carlos->persona_id,
                    'observaciones' => trim(($pago->observaciones ? $pago->observaciones . ' ' : '') . $nota),
                    'updated_at' => now(),
                ]);
                $auditoria->registrar([
                    'modulo' => 'ventas', 'tabla' => 'ventas.pagos', 'accion' => 'ACTUALIZAR', 'registro_id' => $pago->id,
                    'datos_antes' => $pago, 'datos_despues' => DB::table('ventas.pagos')->where('id', $pago->id)->first(),
                    'descripcion' => mb_substr("Pago unido al cobro {$pagoEma->codigo_cobro} del tutor Carlos Montesdeoca (transferencia " . self::REFERENCIA . ').', 0, 255),
                ]);
            }

            // Los $70 de Carlitos, en el mismo turno, caja y fecha en que entró la transferencia.
            $nuevo = (array) $pagoEma;
            unset($nuevo['id']);
            $pagoId = DB::table('ventas.pagos')->insertGetId(array_merge($nuevo, [
                'venta_id' => $venta->id,
                'monto' => 70,
                'metodo_pago' => 'TRANSFERENCIA',
                'efectivo_recibido' => null,
                'cambio_entregado' => null,
                'referencia' => self::REFERENCIA,
                'pagador_persona_id' => $carlos->persona_id,
                'numero_comprobante' => 'PAGO-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'observaciones' => $nota,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $auditoria->registrar([
                'modulo' => 'ventas', 'tabla' => 'ventas.pagos', 'accion' => 'CREAR', 'registro_id' => $pagoId,
                'datos_antes' => null, 'datos_despues' => DB::table('ventas.pagos')->where('id', $pagoId)->first(),
                'descripcion' => mb_substr("Pago de \$70 de Carlitos Montesdeoca Montalvo ({$venta->numero}) con la transferencia " . self::REFERENCIA . ' del 13/11, no registrado en su momento.', 0, 255),
            ]);

            app(VentaServicio::class)->actualizarEstadoVenta((int) $venta->id);
            DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update([
                'pago_id' => $pagoId, 'estado' => 'EMITIDO', 'emitido_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ventas.ventas')->where('id', $venta->id)->update([
                'observaciones' => trim(($venta->observaciones ? $venta->observaciones . ' ' : '') . $nota),
                'updated_at' => now(),
            ]);
            app(CarteraServicio::class)->sincronizarVenta((int) $venta->id);

            // El período pagado queda igual que el de Ema; la membresía sigue pendiente por el mes actual.
            DB::table('membresias.membresia_periodos')->where('id', $p1->id)->update(['estado' => $p1Ema->estado, 'updated_at' => now()]);
            DB::table('membresias.membresia_movimientos')->insert([
                'membresia_id' => $memb20->id, 'tipo' => 'CORRECCION_DATOS', 'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now('America/Guayaquil')->toDateString(),
                'motivo' => 'Período 1 (04/10 al 08/11) pagado con la transferencia ' . self::REFERENCIA . ' del 13/11 ($70), que no se había registrado.',
                'usuario_id' => null, 'autorizado_por' => null,
                'detalle' => json_encode(['venta' => $venta->numero, 'pago_id' => $pagoId, 'codigo_cobro' => $pagoEma->codigo_cobro], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // El turno en que entró la transferencia recalcula sus totales (el efectivo no cambia).
            $turno = DB::table('ventas.turnos_caja')->where('id', $pagoEma->turno_caja_id)->first();
            if ($turno && $turno->estado === 'CERRADA') {
                DB::table('ventas.turnos_caja')->where('id', $turno->id)->update(
                    app(TurnoCajaServicio::class)->resumenCobrosTurno((int) $turno->id) + [
                        'observaciones_cierre' => trim(($turno->observaciones_cierre ? $turno->observaciones_cierre . ' ' : '')
                            . 'Recalculado el 17/11/2026: se agregan $70 por transferencia ' . self::REFERENCIA . ' (Carlitos Montesdeoca) y los pagos de la familia quedan en un solo cobro. El efectivo no cambia.'),
                        'updated_at' => now(),
                    ]);
                $auditoria->registrar([
                    'modulo' => 'ventas', 'tabla' => 'ventas.turnos_caja', 'accion' => 'RECALCULAR_CAJA', 'registro_id' => $turno->id,
                    'datos_antes' => $turno, 'datos_despues' => DB::table('ventas.turnos_caja')->where('id', $turno->id)->first(),
                    'descripcion' => 'Recalculo por transferencia ' . self::REFERENCIA . ' de la familia Montesdeoca.',
                ]);
            }
        });
    }

    public function down(): void
    {
        // Sin reversión automática: los datos anteriores quedan completos en auditoria.eventos.
    }
};
