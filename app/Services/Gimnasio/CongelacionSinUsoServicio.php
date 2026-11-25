<?php

namespace App\Services\Gimnasio;

use App\Services\Configuracion\EstadoCatalogoServicio;
use App\Services\CuentasCobrar\CarteraServicio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Congelar sin uso: para un contrato cuyo período actual no se ha usado (ninguna asistencia) ni
 * pagado (ningún cobro), se anula la cuota emitida y se congela desde el inicio del período. Al
 * reactivar, el período empieza el día del regreso con sus días completos y la cuota se vuelve a
 * emitir ese día. Todo queda en el historial del contrato (movimientos CONGELAR y REACTIVAR).
 */
final class CongelacionSinUsoServicio
{
    public const TIPO = 'SIN_USO';

    public function __construct(
        private readonly EstadoCatalogoServicio $estados,
        private readonly CarteraServicio $cartera,
    ) {}

    /** Indica si el contrato puede congelarse sin uso y, si no, por qué. */
    public function evaluar(int $id): array
    {
        $m = DB::table('membresias.membresias as m')->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->where('m.id', $id)->first(['m.*', 'p.tipo_producto']);
        $no = fn (string $motivo) => ['disponible' => false, 'motivo' => $motivo];
        if (! $m) {
            return $no('La membresía no existe.');
        }
        if ($m->tipo_producto === 'PASE_DIARIO') {
            return $no('Los pases diarios no se congelan.');
        }
        if (! in_array(strtoupper((string) $m->estado), ['ACTIVA', 'PENDIENTE_PAGO'], true)) {
            return $no('Solo se congela un contrato activo o pendiente de pago.');
        }
        if ((bool) ($m->generar_venta_automatica ?? false)) {
            return $no('Este contrato usa cobros recurrentes sin calendario de cuotas.');
        }
        if (DB::table('membresias.membresia_suspensiones')->where('membresia_id', $id)
            ->whereIn('estado', ['PROGRAMADA', 'VIGENTE'])->exists()) {
            return $no('Ya existe una congelación programada o vigente.');
        }

        $hoy = now('America/Guayaquil')->toDateString();
        $periodo = DB::table('membresias.membresia_periodos')->where('membresia_id', $id)
            ->where('fecha_inicio', '<=', $hoy)->orderByDesc('numero_periodo')->first();
        if (! $periodo || ! $periodo->venta_id) {
            return $no('El período actual no tiene una cuota emitida.');
        }
        $venta = DB::table('ventas.ventas')->where('id', $periodo->venta_id)->first();
        if (! $venta || $venta->estado !== 'PENDIENTE') {
            return $no('La cuota del período actual ya tiene cobros o no está pendiente.');
        }
        if (DB::table('ventas.pagos')->where('venta_id', $venta->id)->exists()) {
            return $no('La cuota del período actual ya tiene cobros.');
        }
        $cuota = DB::table('membresias.membresia_cuotas')->where('venta_id', $venta->id)
            ->where('estado', 'EMITIDA')->first();
        if (! $cuota) {
            return $no('La venta del período no pertenece al calendario de cuotas del contrato.');
        }
        if (DB::table('membresias.membresia_periodos')->where('membresia_id', $id)
            ->where('numero_periodo', '>', $periodo->numero_periodo)->whereNotNull('venta_id')->exists()) {
            return $no('El contrato ya tiene cuotas emitidas de períodos siguientes.');
        }
        if (DB::table('ventas.ventas')->where('membresia_id', $id)->where('id', '<>', $venta->id)
            ->whereIn('estado', ['PENDIENTE', 'PARCIAL'])->exists()) {
            return $no('El contrato tiene otras ventas sin pago completo.');
        }
        $asistencias = DB::table('acceso.asistencias')->where('cliente_id', $m->deportista_id)
            ->where('fecha_hora', '>=', $periodo->fecha_inicio)->count();
        if ($asistencias > 0) {
            return $no("El deportista ya asistió {$asistencias} vez/veces en este período: usa la congelación normal.");
        }

        return [
            'disponible' => true,
            'motivo' => null,
            'periodo_id' => (int) $periodo->id,
            'numero_periodo' => (int) $periodo->numero_periodo,
            'periodo_inicio' => substr((string) $periodo->fecha_inicio, 0, 10),
            'periodo_fin' => substr((string) $periodo->fecha_fin, 0, 10),
            'venta_id' => (int) $venta->id,
            'venta_numero' => $venta->numero,
            'monto' => round((float) $venta->total, 2),
            'cuota_id' => (int) $cuota->id,
        ];
    }

    public function congelar(int $id, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): array {
            $m = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
            $e = $this->evaluar($id);
            if (! $m || ! $e['disponible']) {
                throw ValidationException::withMessages(['membresia_id' => $e['motivo'] ?? 'No se puede congelar sin uso.']);
            }
            $codigo = $this->validarMotivo($datos['motivo_codigo'] ?? null, 'CONGELAR');
            $hoy = now('America/Guayaquil')->toDateString();
            $regreso = (string) $datos['fecha_regreso'];
            if ($regreso <= $hoy) {
                throw ValidationException::withMessages(['fecha_regreso' => 'El regreso estimado debe ser posterior a hoy.']);
            }
            $motivo = trim((string) $datos['motivo']);
            $finPrevisto = CarbonImmutable::parse($regreso)->subDay()->toDateString();

            // 1. Anular la cuota emitida: sale de Cuentas abiertas y de Cartera, con su motivo.
            $venta = DB::table('ventas.ventas')->where('id', $e['venta_id'])->lockForUpdate()->first();
            DB::table('ventas.ventas')->where('id', $venta->id)->update($this->estados->aplicar([
                'estado' => 'ANULADA',
                'observaciones' => trim(($venta->observaciones ? $venta->observaciones.' ' : '')
                    .'Anulada por congelación sin uso: '.$motivo),
                'updated_at' => now(),
            ], 'VENTA'));
            DB::table('ventas.comprobantes')->where('venta_id', $venta->id)->update(['estado' => 'ANULADO', 'updated_at' => now()]);
            $this->cartera->sincronizarVenta((int) $venta->id);
            $cuentaId = DB::table('cuentas_cobrar.cuentas')->where('venta_id', $venta->id)->value('id');
            if ($cuentaId) {
                DB::table('cuentas_cobrar.compromisos_pago')->where('cuenta_id', $cuentaId)->where('estado', 'PENDIENTE')
                    ->update(['estado' => 'CANCELADO', 'updated_at' => now()]);
            }
            DB::table('membresias.membresia_cuotas')->where('id', $e['cuota_id'])
                ->update(['estado' => 'ANULADA', 'anulada_at' => now(), 'updated_at' => now()]);
            DB::table('membresias.membresia_periodos')->where('id', $e['periodo_id'])
                ->update(['venta_id' => null, 'descuento_id' => null, 'descuento_importe' => 0, 'updated_at' => now()]);

            // 2. Congelar desde el inicio del período: no se pierde ningún día.
            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::CONGELAR,
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => $e['periodo_inicio'],
                'motivo' => $motivo,
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'detalle' => json_encode([
                    'tipo' => self::TIPO,
                    'motivo_codigo' => $codigo,
                    'periodo_id' => $e['periodo_id'],
                    'numero_periodo' => $e['numero_periodo'],
                    'periodo_inicio' => $e['periodo_inicio'],
                    'periodo_fin' => $e['periodo_fin'],
                    'cuota_id' => $e['cuota_id'],
                    'venta_anulada' => ['id' => $e['venta_id'], 'numero' => $e['venta_numero'], 'total' => $e['monto']],
                    'regreso_estimado' => $regreso,
                    'registrado_el' => $hoy,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $suspensionId = DB::table('membresias.membresia_suspensiones')->insertGetId([
                'membresia_id' => $id,
                'movimiento_inicio_id' => $movimientoId,
                'tipo' => self::TIPO,
                'estado' => 'VIGENTE',
                'fecha_inicio' => $e['periodo_inicio'],
                'fecha_fin_prevista' => $finPrevisto,
                'dias_reconocidos' => 0,
                'fecha_fin_original' => substr((string) $m->fecha_fin, 0, 10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('membresias.membresias')->where('id', $id)->update([
                'estado' => 'CONGELADA',
                'estado_id' => $this->estados->idPorValor('MEMBRESIA', 'CONGELADA'),
                'updated_at' => now(),
            ]);

            return $e + [
                'suspension_id' => $suspensionId, 'movimiento_id' => $movimientoId,
                'tipo' => self::TIPO, 'inicio' => $e['periodo_inicio'], 'fin_previsto' => $finPrevisto,
                'regreso_estimado' => $regreso,
            ];
        });
    }

    /** Cálculo del regreso: el período empieza hoy con sus días completos y su cuota se emite hoy. */
    public function previsualizarReactivacion(int $id, object $s): array
    {
        $detalle = $this->detalle($s);
        $hoy = CarbonImmutable::today('America/Guayaquil');
        $inicioAnterior = CarbonImmutable::parse($detalle['periodo_inicio']);
        $desplazamiento = (int) $inicioAnterior->diffInDays($hoy, false);
        $duracion = (int) $inicioAnterior->diffInDays(CarbonImmutable::parse($detalle['periodo_fin']));
        $cuota = DB::table('membresias.membresia_cuotas')->where('id', $detalle['cuota_id'])->first();

        return [
            'confirmable' => $desplazamiento >= 0 && $cuota && $cuota->estado === 'ANULADA',
            'motivo_bloqueo' => $cuota && $cuota->estado === 'ANULADA' ? null : 'La cuota anulada cambió; requiere revisión.',
            'tipo' => self::TIPO,
            'suspension_id' => (int) $s->id,
            'fecha_regreso' => $hoy->toDateString(),
            'fecha_fin_real' => $hoy->subDay()->toDateString(),
            'dias_reconocidos' => max(0, $desplazamiento),
            'fecha_fin_original' => substr((string) $s->fecha_fin_original, 0, 10),
            'fecha_fin_propuesta' => CarbonImmutable::parse($s->fecha_fin_original)->addDays(max(0, $desplazamiento))->toDateString(),
            'periodo_inicio_nuevo' => $hoy->toDateString(),
            'periodo_fin_nuevo' => $hoy->addDays($duracion)->toDateString(),
            'cuota_a_emitir' => $cuota ? round((float) $cuota->monto, 2) : 0,
            'impacto_financiero' => ['pagos_confirmados' => 0, 'deuda_emitida' => 0],
        ];
    }

    public function reactivar(int $id, object $m, object $s, string $motivo, ?int $usuarioId, ?string $codigo): array
    {
        $previa = $this->previsualizarReactivacion($id, $s);
        if (! $previa['confirmable']) {
            throw ValidationException::withMessages(['membresia_id' => $previa['motivo_bloqueo'] ?? 'La reactivación requiere revisión.']);
        }
        $detalle = $this->detalle($s);
        $hoy = $previa['fecha_regreso'];
        $dias = $previa['dias_reconocidos'];

        $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
            'membresia_id' => $id,
            'tipo' => PoliticaCicloVidaMembresia::REACTIVAR,
            'estado' => 'CONFIRMADO',
            'fecha_efectiva' => $hoy,
            'motivo' => trim($motivo),
            'usuario_id' => $usuarioId,
            'autorizado_por' => $usuarioId,
            'movimiento_origen_id' => $s->movimiento_inicio_id,
            'detalle' => json_encode([
                'tipo' => self::TIPO,
                'fecha_regreso' => $hoy,
                'dias_reconocidos' => $dias,
                'periodo_inicio_nuevo' => $previa['periodo_inicio_nuevo'],
                'periodo_fin_nuevo' => $previa['periodo_fin_nuevo'],
                'fecha_fin_anterior' => $previa['fecha_fin_original'],
                'fecha_fin_nueva' => $previa['fecha_fin_propuesta'],
                'cuota_id' => $detalle['cuota_id'],
                'motivo_codigo' => $codigo,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('membresias.membresia_suspensiones')->where('id', $s->id)->update([
            'estado' => 'FINALIZADA', 'fecha_fin_real' => $previa['fecha_fin_real'],
            'dias_reconocidos' => $dias, 'fecha_fin_ajustada' => $previa['fecha_fin_propuesta'],
            'movimiento_fin_id' => $movimientoId, 'updated_at' => now(),
        ]);

        // El período congelado y los siguientes se corren al día del regreso.
        $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $id)
            ->where('numero_periodo', '>=', $detalle['numero_periodo'])->lockForUpdate()->get();
        foreach ($periodos as $p) {
            DB::table('membresias.membresia_periodos')->where('id', $p->id)->update([
                'fecha_inicio' => CarbonImmutable::parse($p->fecha_inicio)->addDays($dias)->toDateString(),
                'fecha_fin' => CarbonImmutable::parse($p->fecha_fin)->addDays($dias)->toDateString(),
                'updated_at' => now(),
            ]);
        }
        // Cuotas aún no emitidas se corren lo mismo; la anulada vuelve a programarse para hoy.
        DB::table('membresias.membresia_cuotas')->where('membresia_id', $id)->where('estado', 'PROGRAMADA')
            ->whereNull('venta_id')->orderBy('id')->get()
            ->each(fn ($c) => DB::table('membresias.membresia_cuotas')->where('id', $c->id)->update([
                'fecha_programada_emision' => CarbonImmutable::parse($c->fecha_programada_emision)->addDays($dias)->toDateString(),
                'fecha_vencimiento' => CarbonImmutable::parse($c->fecha_vencimiento)->addDays($dias)->toDateString(),
                'updated_at' => now(),
            ]));
        DB::table('membresias.membresia_cuotas')->where('id', $detalle['cuota_id'])->update([
            'estado' => 'PROGRAMADA', 'venta_id' => null, 'emitida_at' => null, 'anulada_at' => null,
            'fecha_programada_emision' => $hoy, 'fecha_vencimiento' => $hoy, 'updated_at' => now(),
        ]);

        // El contrato vuelve con el período sin pagar: pendiente de pago, con su día de pago nuevo.
        DB::table('membresias.membresias')->where('id', $id)->update([
            'estado' => 'PENDIENTE_PAGO',
            'estado_id' => $this->estados->idPorValor('MEMBRESIA', 'PENDIENTE_PAGO'),
            'fecha_fin' => $previa['fecha_fin_propuesta'],
            'dia_pago' => (int) substr($hoy, 8, 2),
            'updated_at' => now(),
        ]);
        app(EmisionCuotasServicio::class)->procesar($hoy);

        return [
            'membresia_id' => $id, 'suspension_id' => (int) $s->id, 'tipo' => self::TIPO,
            'fecha_regreso' => $hoy, 'fecha_fin_real' => $previa['fecha_fin_real'],
            'dias_reconocidos' => $dias,
            'fecha_fin_original' => $previa['fecha_fin_original'],
            'fecha_fin' => $previa['fecha_fin_propuesta'],
            'cuota_emitida' => DB::table('membresias.membresia_cuotas as c')->join('ventas.ventas as v', 'v.id', '=', 'c.venta_id')
                ->where('c.id', $detalle['cuota_id'])->value('v.numero'),
        ];
    }

    private function detalle(object $s): array
    {
        $mov = DB::table('membresias.membresia_movimientos')->where('id', $s->movimiento_inicio_id)->first();
        $detalle = json_decode((string) ($mov->detalle ?? '{}'), true) ?: [];
        if (($detalle['tipo'] ?? null) !== self::TIPO || empty($detalle['cuota_id'])) {
            throw ValidationException::withMessages(['membresia_id' => 'La congelación sin uso no tiene su detalle completo; requiere revisión.']);
        }
        return $detalle;
    }

    private function validarMotivo(?string $codigo, string $operacion): string
    {
        $codigo = mb_strtoupper(trim((string) $codigo));
        if ($codigo === '' || ! DB::table('membresias.motivos_ciclo_vida')->where('codigo', $codigo)
            ->where('operacion', $operacion)->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['motivo_codigo' => 'Selecciona un motivo de congelación activo.']);
        }
        return $codigo;
    }
}
