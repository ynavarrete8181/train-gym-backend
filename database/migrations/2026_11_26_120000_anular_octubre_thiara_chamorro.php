<?php

use App\Services\Configuracion\EstadoCatalogoServicio;
use App\Services\CuentasCobrar\CarteraServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Thiara Chamorro (MEMB-000003, tutor Xavier Chamorro): el contrato se registró el 31/10/2026 con
 * inicio retroactivo al 01/10/2026, por eso el sistema cobró octubre (venta #6) y luego renovó
 * noviembre (venta #11). Thiara empezó en noviembre y no usó octubre (sin asistencias ni pagos).
 * Por pedido de la administración se anula octubre con historial: venta y comprobante anulados,
 * cuenta por cobrar cerrada, cuota anulada, período cancelado y el contrato empieza el 01/11/2026.
 * Noviembre ($100) queda como única deuda. Solo actúa si todo sigue como se revisó.
 */
return new class extends Migration
{
    private const MOTIVO = 'Contrato registrado el 31/10/2026 con inicio retroactivo al 01/10/2026; Thiara empezó en noviembre '
        .'y no usó octubre (sin asistencias ni pagos). Se anula la cuota de octubre por pedido de la administración.';

    public function up(): void
    {
        $m = DB::table('membresias.membresias')->where('id', 3)->where('codigo_contrato', 'MEMB-000003')->first();
        $nombre = DB::table('clientes.deportistas as d')->join('personas.personas as p', 'p.id', '=', 'd.persona_id')
            ->where('d.id', 5)->value('p.nombre_completo');
        $venta = DB::table('ventas.ventas')->where('id', 6)->first();
        $cuota = DB::table('membresias.membresia_cuotas')->where('id', 33)->first();
        $periodo = DB::table('membresias.membresia_periodos')->where('id', 3)->first();
        $pagos = DB::table('ventas.pagos')->where('venta_id', 6)->count();

        $ok = $m && (int) $m->deportista_id === 5 && $nombre === 'Thiara Chamorro'
            && $venta && (int) $venta->membresia_id === 3 && $venta->estado === 'PENDIENTE' && (float) $venta->total === 100.0
            && $cuota && (int) $cuota->venta_id === 6 && $cuota->estado === 'EMITIDA'
            && $periodo && (int) $periodo->membresia_id === 3 && (int) $periodo->venta_id === 6
            && substr((string) $periodo->fecha_inicio, 0, 10) === '2026-10-01'
            && $pagos === 0;
        if (! $ok) {
            Log::warning('Anular octubre Thiara: los datos ya no coinciden con lo revisado; no se cambia nada.');
            return;
        }

        $estados = app(EstadoCatalogoServicio::class);
        $usuario = (int) (DB::table('seguridad.users')->where('name', 'Root')->value('id') ?? 1);
        $antes = [
            'membresia' => $m,
            'venta' => $venta,
            'cuota' => $cuota,
            'periodo' => $periodo,
            'cuenta' => DB::table('cuentas_cobrar.cuentas')->where('venta_id', 6)->first(),
            'comprobantes' => DB::table('ventas.comprobantes')->where('venta_id', 6)->get(),
        ];

        DB::transaction(function () use ($m, $venta, $estados, $usuario, $antes) {
            DB::table('ventas.ventas')->where('id', 6)->update($estados->aplicar([
                'estado' => 'ANULADA',
                'observaciones' => trim(($venta->observaciones ? $venta->observaciones.' ' : '').'Anulada: '.self::MOTIVO),
                'updated_at' => now(),
            ], 'VENTA'));
            DB::table('ventas.comprobantes')->where('venta_id', 6)->update(['estado' => 'ANULADO', 'updated_at' => now()]);
            app(CarteraServicio::class)->sincronizarVenta(6);
            $cuentaId = DB::table('cuentas_cobrar.cuentas')->where('venta_id', 6)->value('id');
            if ($cuentaId) {
                DB::table('cuentas_cobrar.compromisos_pago')->where('cuenta_id', $cuentaId)->where('estado', 'PENDIENTE')
                    ->update(['estado' => 'CANCELADO', 'updated_at' => now()]);
            }
            DB::table('membresias.membresia_cuotas')->where('id', 33)
                ->update(['estado' => 'ANULADA', 'anulada_at' => now(), 'updated_at' => now()]);
            DB::table('membresias.membresia_periodos')->where('id', 3)
                ->update(['estado' => 'CANCELADA', 'venta_id' => null, 'updated_at' => now()]);

            // El contrato empieza en noviembre: un solo período contratado.
            $condiciones = json_decode((string) $m->condiciones_contratadas, true) ?: [];
            $condiciones = array_merge($condiciones, [
                'fecha_inicio' => '2026-11-01',
                'fecha_primer_pago' => '2026-11-01',
                'cantidad_periodos' => 1,
                'total_bruto_referencial' => '100.00',
            ]);
            DB::table('membresias.membresias')->where('id', 3)->update([
                'fecha_inicio' => '2026-11-01',
                'cantidad_periodos_contratados' => 1,
                'condiciones_contratadas' => json_encode($condiciones, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            DB::table('membresias.membresia_movimientos')->insert([
                'membresia_id' => 3,
                'tipo' => 'CORRECCION_DATOS',
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => '2026-11-01',
                'motivo' => self::MOTIVO,
                'usuario_id' => $usuario,
                'autorizado_por' => $usuario,
                'detalle' => json_encode([
                    'fecha_inicio_anterior' => '2026-10-01',
                    'fecha_inicio_nueva' => '2026-11-01',
                    'periodo_cancelado' => ['id' => 3, 'inicio' => '2026-10-01', 'fin' => '2026-10-31'],
                    'cuota_anulada' => 33,
                    'venta_anulada' => ['id' => 6, 'numero' => $venta->numero, 'total' => 100.00],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('auditoria.eventos')->insert([
                'usuario_id' => $usuario,
                'usuario_nombre' => 'Sistema (migración)',
                'modulo' => 'gimnasio',
                'tabla' => 'membresias.membresias',
                'registro_id' => '3',
                'accion' => 'ANULAR_CUOTA',
                'descripcion' => 'MEMB-000003 (Thiara Chamorro): se anula octubre, venta '.$venta->numero.' de $100. '.self::MOTIVO,
                'datos_antes' => json_encode($antes, JSON_UNESCAPED_UNICODE),
                'datos_despues' => json_encode(['venta_6' => 'ANULADA', 'cuota_33' => 'ANULADA', 'periodo_3' => 'CANCELADA', 'fecha_inicio' => '2026-11-01'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
        Log::info('Anular octubre Thiara: aplicado.');
    }

    public function down(): void
    {
        // Corrección de datos con historial; la copia previa está en auditoria.eventos.
    }
};
