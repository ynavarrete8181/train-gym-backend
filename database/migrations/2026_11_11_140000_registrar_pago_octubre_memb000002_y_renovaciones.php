<?php

use App\Services\Ventas\VentaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Las renovaciones creadas por el cobro programado no quedaban en el historial
 *    comercial, por eso la pestaña Renovaciones salía vacía. Se agregan.
 * 2. MEMB-000002 (Silvia Palma): la cuota de octubre se pagó por transferencia
 *    Banco Pichincha (comprobante 95263911) el 01/11/2026, sin caja abierta ese día.
 *    Se registra en el turno de Home del 04/11/2026, como pidió administración.
 */
return new class extends Migration
{
    private const VENTA = 'VENTA-20261031222309-460';
    private const REFERENCIA = '95263911';
    private const TURNO_ID = 4;
    private const FECHA_PAGO = '2026-11-01 12:00:00';

    public function up(): void
    {
        $this->registrarRenovacionesFaltantes();
        $this->registrarPagoOctubre();
    }

    private function registrarRenovacionesFaltantes(): void
    {
        $periodos = DB::table('membresias.membresia_periodos as p')
            ->where('p.numero_periodo', '>', 1)
            ->where('p.renovacion_manual', false)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('membresias.membresia_condiciones_historial as h')
                ->whereColumn('h.membresia_id', 'p.membresia_id')
                ->whereIn('h.accion', ['RENOVACION_AUTOMATICA', 'RENOVACION_MANUAL'])
                ->whereRaw("(h.condiciones->>'numero_periodo_nuevo')::int = p.numero_periodo"))
            ->orderBy('p.membresia_id')->orderBy('p.numero_periodo')
            ->get(['p.id', 'p.membresia_id', 'p.numero_periodo', 'p.fecha_inicio', 'p.fecha_fin', 'p.precio', 'p.created_at']);

        foreach ($periodos as $p) {
            $version = (int) DB::table('membresias.membresia_condiciones_historial')
                ->where('membresia_id', $p->membresia_id)->max('version') + 1;
            DB::table('membresias.membresia_condiciones_historial')->insert([
                'membresia_id' => $p->membresia_id,
                'version' => $version,
                'accion' => 'RENOVACION_AUTOMATICA',
                'condiciones' => json_encode([
                    'periodo_id' => (int) $p->id,
                    'numero_periodo_nuevo' => (int) $p->numero_periodo,
                    'fecha_inicio' => $p->fecha_inicio,
                    'fecha_fin' => $p->fecha_fin,
                    'precio' => $p->precio,
                    'origen' => 'COBRO_PROGRAMADO',
                ], JSON_THROW_ON_ERROR),
                'usuario_id' => null,
                'motivo' => 'Renovación automática por cobro programado (registrada después).',
                'registrado_at' => $p->created_at ?? now(),
            ]);
        }
    }

    private function registrarPagoOctubre(): void
    {
        $venta = DB::table('ventas.ventas')->where('numero', self::VENTA)->lockForUpdate()->first();
        $turno = DB::table('ventas.turnos_caja')->where('id', self::TURNO_ID)->first();
        if (! $venta || ! $turno || $venta->estado !== 'PENDIENTE' || (int) $turno->sede_id !== 1) {
            return; // Otra base (desarrollo) o ya registrado.
        }
        $yaPagado = DB::table('ventas.pagos')->where('venta_id', $venta->id)->where('estado', 'CONFIRMADO')->exists()
            || DB::table('ventas.pagos')->where('referencia', self::REFERENCIA)->exists();
        if ($yaPagado) {
            return;
        }

        $prefijo = 'COBRO-' . date('Ymd', strtotime((string) $turno->fecha_apertura)) . '-';
        $ultimo = DB::table('ventas.pagos')->where('codigo_cobro', 'like', $prefijo . '%')->orderByDesc('codigo_cobro')->value('codigo_cobro');
        $codigo = $prefijo . str_pad((string) ($ultimo ? ((int) substr($ultimo, -4)) + 1 : 1), 4, '0', STR_PAD_LEFT);
        $marca = now()->format('YmdHis');

        $pagoId = DB::table('ventas.pagos')->insertGetId([
            'venta_id' => $venta->id,
            'caja_id' => (int) $turno->caja_id,
            'turno_caja_id' => (int) $turno->id,
            'usuario_id' => (int) $turno->usuario_id,
            'numero_comprobante' => 'PAGO-' . $marca . '-' . random_int(100, 999),
            'operacion_cobro_id' => 'COBRO-OP-' . $marca . '-' . random_int(1000, 9999),
            'codigo_cobro' => $codigo,
            'metodo_pago' => 'TRANSFERENCIA',
            'monto' => round((float) $venta->total, 2),
            'estado' => 'CONFIRMADO',
            'estado_id' => DB::table('configuracion.estados_catalogo')->where('entidad', 'PAGO')->where('valor_interno', 'CONFIRMADO')->value('id'),
            'fecha_pago' => self::FECHA_PAGO,
            'referencia' => self::REFERENCIA,
            'observaciones' => 'Transferencia Banco Pichincha, comprobante ' . self::REFERENCIA
                . '. Pagada el 01/11/2026 (sin caja abierta ese día); registrada en el turno de Home del 04/11/2026.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Misma lógica que un cobro de caja: estado de la venta, período, membresía y cartera.
        $servicio = app(VentaServicio::class);
        (fn () => $this->actualizarEstadoVenta((int) $venta->id, (int) $turno->usuario_id))->call($servicio);

        DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update([
            'pago_id' => $pagoId,
            'estado' => 'EMITIDO',
            'emitido_at' => self::FECHA_PAGO,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Corrección de datos: no se revierte.
    }
};
