<?php

namespace App\Services\Gimnasio;

use App\Services\Configuracion\EstadoCatalogoServicio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida auditado. Las vacaciones de TIEMPO admiten programación
 * futura sin alterar anticipadamente acceso, cobros ni fecha fin contractual.
 * Todas las transiciones revalidan y bloquean primero la membresía.
 */
final class CicloVidaMembresiaServicio
{
    public function __construct(
        private readonly PoliticaCicloVidaMembresia $politica,
        private readonly EstadoCatalogoServicio $estados,
        private readonly ImpactoFinancieroCongelacionServicio $impactoFinanciero,
    ) {}

    public function resumen(int $id): array
    {
        $m = DB::table('membresias.membresias')->where('id', $id)->first();
        if (! $m) {
            throw ValidationException::withMessages(['membresia_id' => 'La membresía no existe.']);
        }

        $movimientos = DB::table('membresias.membresia_movimientos')
            ->where('membresia_id', $id)->orderByDesc('id')->limit(30)->get();
        $suspensiones = DB::table('membresias.membresia_suspensiones')
            ->where('membresia_id', $id)->orderByDesc('id')->get();

        return [
            'membresia_id' => $id,
            'estado' => $m->estado,
            'fecha_fin' => $m->fecha_fin,
            'movimientos' => $movimientos,
            'suspensiones' => $suspensiones,
            'puede_congelar_tiempo' => strtoupper((string) $m->estado) === 'ACTIVA'
                && $this->motivoBloqueoFinanciero($id, $m) === null
                && ! $suspensiones->contains(fn ($s) => in_array($s->estado, ['PROGRAMADA', 'VIGENTE'], true)),
            'permite_congelar_facturacion' => false,
        ];
    }

    /**
     * TIEMPO: contratos sin cobro recurrente con ventas emitidas pagadas.
     * Las cuotas futuras conservan el día de pago; no se reprograman.
     */
    private function motivoBloqueoFinanciero(int $id, object $m): ?string
    {
        if ((bool) ($m->generar_venta_automatica ?? false)
            || (bool) ($m->renovacion_automatica ?? false)) {
            return 'Desactiva los cobros recurrentes mediante el proceso financiero de Fase 3.';
        }

        $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $id)->get();
        if ($periodos->isEmpty()) {
            return 'El contrato no tiene períodos registrados para compensar días.';
        }

        // En TIEMPO las cuotas todavía PROGRAMADA conservan su día de pago.
        // No se modifica el calendario de facturación; la revisión se repite
        // al inicio y solo procede si las ventas emitidas están pagadas.
        if (DB::table('ventas.ventas')->where('membresia_id', $id)
            ->where('estado', '!=', 'ANULADA')
            ->where('estado', '!=', 'PAGADA')->exists()) {
            return 'Existen ventas sin pago completo; revisa Ventas y Cartera antes de congelar.';
        }
        if (! DB::table('ventas.ventas')->where('membresia_id', $id)->where('estado', 'PAGADA')->exists()) {
            return 'La congelación de tiempo requiere un contrato pagado y confirmado.';
        }
        return null;
    }

    public function previsualizar(int $id, array $datos, ?int $ignorarSuspensionId = null): array
    {
        $m = DB::table('membresias.membresias')->where('id', $id)->first();
        if (! $m) {
            throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
        }
        $tipo = strtoupper((string) $datos['tipo']);
        if (! in_array($tipo, [PoliticaCicloVidaMembresia::TIEMPO, PoliticaCicloVidaMembresia::FACTURACION], true)) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de congelación no reconocido.']);
        }
        // La edición solo puede excluir su propia programación validada.
        // Nunca se permite omitir otras suspensiones del contrato.
        if ($ignorarSuspensionId !== null) {
            $propia = DB::table('membresias.membresia_suspensiones')
                ->where('id', $ignorarSuspensionId)->where('membresia_id', $id)
                ->where('estado', 'PROGRAMADA')->where('tipo', PoliticaCicloVidaMembresia::TIEMPO)
                ->where('fecha_inicio', '>', now('America/Guayaquil')->toDateString())
                ->exists();
            if (! $propia || $tipo !== PoliticaCicloVidaMembresia::TIEMPO) {
                throw ValidationException::withMessages([
                    'suspension_id' => 'Únicamente puede modificarse una congelación de tiempo futura y programada.',
                ]);
            }
        }
        $consultaSuspensiones = DB::table('membresias.membresia_suspensiones')
            ->where('membresia_id', $id)->whereIn('estado', ['PROGRAMADA', 'VIGENTE']);
        if ($ignorarSuspensionId !== null) {
            $consultaSuspensiones->where('id', '!=', $ignorarSuspensionId);
        }
        $existe = $consultaSuspensiones->exists();
        $hayCuotas = DB::table('membresias.membresia_cuotas')
            ->where('membresia_id', $id)->where('estado', 'PROGRAMADA')->exists();
        $this->politica->validarCongelacion((string) $m->estado, $tipo, $existe, $hayCuotas);
        $intervalo = $this->politica->validarIntervalo($datos['fecha_inicio'], $datos['fecha_fin'], now('America/Guayaquil')->toDateString());
        $hoy = now('America/Guayaquil')->toDateString();
        // Una vista previa puede planificarse a futuro, sin registrar operaciones.
        // Solo la ejecución real exige inicio hoy y ausencia de bloqueos financieros.
        if ($intervalo['fecha_fin'] > substr((string) $m->fecha_fin, 0, 10)) {
            throw ValidationException::withMessages(['fecha_fin' => 'La ausencia no puede superar la vigencia restante del contrato.']);
        }
        $financiero = $this->impactoFinanciero->previsualizar(
            $id, $intervalo['fecha_inicio'], $intervalo['fecha_fin'], $tipo
        );
        $bloqueo = $this->motivoBloqueoFinanciero($id, $m);
        // En FACTURACION se acepta un contrato a cuotas solo si no hay deudas
        // emitidas, ni ventas periódicas recurrentes, ni cuotas vencidas sin emitir.
        // TIEMPO conserva la política conservadora de la Fase 2.
        if ($tipo === PoliticaCicloVidaMembresia::FACTURACION) {
            $bloqueo = null;
            if ((bool) ($m->generar_venta_automatica ?? false)
                || (bool) ($m->renovacion_automatica ?? false)
                || $m->cantidad_periodos_contratados === null) {
                $bloqueo = 'Este contrato utiliza cobros recurrentes sin calendario de cuotas fijo.';
            } elseif ($financiero['requiere_revision_cartera']) {
                $bloqueo = 'El contrato tiene una venta emitida con saldo pendiente; requiere conciliación de Cartera.';
            } elseif (collect($financiero['cuotas'])->contains(fn ($c) =>
                $c['estado'] === 'PROGRAMADA'
                && $c['emision_anterior'] < $intervalo['fecha_inicio'])) {
                $bloqueo = 'Existen cuotas no emitidas cuyo plazo de emisión ya comenzó; deben regularizarse antes.';
            } elseif ($financiero['cuotas_reprogramables'] === 0) {
                $bloqueo = 'No existen cuotas futuras sin emitir que puedan reprogramarse.';
            }
        }
        $inicioFuturo = $intervalo['fecha_inicio'] > $hoy;
        $programable = $inicioFuturo && $tipo === PoliticaCicloVidaMembresia::TIEMPO
            && $bloqueo === null;
        $motivosBloqueo = array_values(array_filter([
            $inicioFuturo ? ($programable
                ? 'Puedes registrar esta solicitud futura. La membresía seguirá activa hasta su fecha de inicio y se revisará nuevamente ese día.'
                : 'Esta modalidad no puede registrarse por anticipado; solo puede simularse.') : null,
            $bloqueo,
        ]));
        // La simulación no cambia datos. Un contrato puede ser simulable sin ser confirmable.
        return [
            'confirmable' => ! $inicioFuturo && $bloqueo === null,
            'programable' => $programable,
            'motivo_bloqueo' => $motivosBloqueo ? implode(' ', $motivosBloqueo) : null,
            'programacion_futura' => $inicioFuturo,
            'cuotas_programadas' => DB::table('membresias.membresia_cuotas')
                ->where('membresia_id', $id)->where('estado', 'PROGRAMADA')->count(),
            'membresia_id' => $id,
            'tipo' => $tipo,
            'inicio' => $intervalo['fecha_inicio'],
            'fin_previsto' => $intervalo['fecha_fin'],
            'dias_previstos' => $intervalo['dias'],
            'fecha_fin_original' => substr((string) $m->fecha_fin, 0, 10),
            'fecha_fin_ajustada' => $this->politica->calcularFinAjustado((string) $m->fecha_fin, $intervalo['dias']),
            'genera_venta' => false,
            'modifica_pagos' => false,
            'requiere_reprogramacion_financiera' => $tipo === PoliticaCicloVidaMembresia::FACTURACION,
            'impacto_financiero' => $financiero,
        ];
    }

    /**
     * Registra una autorización de vacaciones futuras. El scheduler la activa
     * exclusivamente en su fecha, tras una nueva validación económica.
     */
    private function validarMotivoCatalogado(?string $codigo, string $operacion): ?string
    {
        if ($codigo === null || trim($codigo) === '') {
            return null;
        }
        $codigo = mb_strtoupper(trim($codigo));
        if (! DB::table('membresias.motivos_ciclo_vida')
            ->where('codigo', $codigo)
            ->where('operacion', $operacion)
            ->where('activo', true)->exists()) {
            throw ValidationException::withMessages([
                'motivo_codigo' => 'El motivo seleccionado no está activo para esta operación.',
            ]);
        }
        return $codigo;
    }

    public function programarTiempo(int $id, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): array {
            $m = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
            }
            if (strtoupper((string) ($datos['tipo'] ?? '')) !== PoliticaCicloVidaMembresia::TIEMPO) {
                throw ValidationException::withMessages([
                    'tipo' => 'La programación anticipada se limita a congelación de tiempo (vacaciones).',
                ]);
            }
            $previa = $this->previsualizar($id, $datos);
            if (! ($previa['programable'] ?? false)) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => $previa['motivo_bloqueo'] ?? 'No es posible programar estas vacaciones.',
                ]);
            }

            $codigoMotivo = $this->validarMotivoCatalogado($datos['motivo_codigo'] ?? null, 'CONGELAR');
            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::CONGELAR,
                'estado' => 'PROGRAMADO',
                'fecha_efectiva' => $previa['inicio'],
                'motivo' => trim($datos['motivo']),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'detalle' => json_encode($previa + ['motivo_codigo' => $codigoMotivo], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $suspensionId = DB::table('membresias.membresia_suspensiones')->insertGetId([
                'membresia_id' => $id,
                'movimiento_inicio_id' => $movimientoId,
                'tipo' => PoliticaCicloVidaMembresia::TIEMPO,
                'estado' => 'PROGRAMADA',
                'fecha_inicio' => $previa['inicio'],
                'fecha_fin_prevista' => $previa['fin_previsto'],
                'dias_reconocidos' => 0,
                'fecha_fin_original' => $previa['fecha_fin_original'],
                'fecha_fin_ajustada' => $previa['fecha_fin_ajustada'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // No tocar membresía, períodos, cuotas, ventas ni Cartera todavía.
            return ['suspension_id' => $suspensionId, 'movimiento_id' => $movimientoId,
                'estado' => 'PROGRAMADA'] + $previa;
        });
    }

    /**
     * Ajusta las fechas de una solicitud de vacaciones todavía PROGRAMADA.
     * Mantiene el identificador original y añade un movimiento de auditoría.
     */
    public function actualizarProgramada(int $id, int $suspensionId, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $suspensionId, $datos, $usuarioId): array {
            $m = DB::table('membresias.membresias')->where('id', $id)
                ->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
            }
            $s = DB::table('membresias.membresia_suspensiones')
                ->where('membresia_id', $id)->where('id', $suspensionId)
                ->lockForUpdate()->first();
            if (! $s || $s->estado !== 'PROGRAMADA'
                || $s->tipo !== PoliticaCicloVidaMembresia::TIEMPO
                || substr((string) $s->fecha_inicio, 0, 10) <= now('America/Guayaquil')->toDateString()) {
                throw ValidationException::withMessages([
                    'suspension_id' => 'Solo puedes editar una congelación de tiempo programada antes de su inicio.',
                ]);
            }
            $previa = $this->previsualizar($id, $datos + ['tipo' => 'TIEMPO'], $suspensionId);
            if (! $previa['programable']) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => $previa['motivo_bloqueo'] ?? 'La nueva programación no cumple los requisitos.',
                ]);
            }

            $original = DB::table('membresias.membresia_movimientos')
                ->where('id', $s->movimiento_inicio_id)->first();
            $detalleOriginal = $original && $original->detalle
                ? (is_string($original->detalle) ? json_decode($original->detalle, true) : (array) $original->detalle)
                : [];
            $codigo = $this->validarMotivoCatalogado(
                $datos['motivo_codigo'] ?? ($detalleOriginal['motivo_codigo'] ?? null), 'CONGELAR'
            );
            $modificacionId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => 'REPROGRAMAR_CONGELACION',
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now('America/Guayaquil')->toDateString(),
                'motivo' => trim($datos['motivo']),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'movimiento_origen_id' => $s->movimiento_inicio_id,
                'detalle' => json_encode([
                    'suspension_id' => $s->id,
                    'antes' => ['fecha_inicio' => $s->fecha_inicio,
                        'fecha_fin_prevista' => $s->fecha_fin_prevista,
                        'fecha_fin_ajustada' => $s->fecha_fin_ajustada,
                        'motivo' => $original->motivo ?? null],
                    'despues' => ['fecha_inicio' => $previa['inicio'],
                        'fecha_fin_prevista' => $previa['fin_previsto'],
                        'fecha_fin_ajustada' => $previa['fecha_fin_ajustada'],
                        'motivo' => trim($datos['motivo'])],
                    'impacto_financiero' => $previa['impacto_financiero'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('membresias.membresia_suspensiones')->where('id', $s->id)->update([
                'fecha_inicio' => $previa['inicio'],
                'fecha_fin_prevista' => $previa['fin_previsto'],
                'fecha_fin_ajustada' => $previa['fecha_fin_ajustada'],
                'updated_at' => now(),
            ]);
            // Mantener sincronizado el movimiento original: el scheduler lo
            // utiliza para la fecha efectiva y la ficha usa su motivo.
            DB::table('membresias.membresia_movimientos')
                ->where('id', $s->movimiento_inicio_id)->update([
                    'fecha_efectiva' => $previa['inicio'],
                    'motivo' => trim($datos['motivo']),
                    'detalle' => json_encode($previa + [
                        'motivo_codigo' => $codigo,
                        'ultima_reprogramacion_id' => $modificacionId,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            // No se tocan membresía, cuotas, ventas, pagos ni Cartera.
            return ['suspension_id' => (int) $s->id, 'movimiento_id' => $modificacionId,
                'estado' => 'PROGRAMADA'] + $previa;
        });
    }

    public function previsualizarEdicion(int $id, int $suspensionId, array $datos): array
    {
        return $this->previsualizar($id, $datos + ['tipo' => 'TIEMPO'], $suspensionId);
    }

    /**
     * Desiste de una solicitud futura, con movimiento propio de auditoría.
     * No borra la aprobación original, ni altera el contrato o sus pagos.
     */
    public function cancelarProgramada(int $id, int $suspensionId, string $motivo, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $suspensionId, $motivo, $usuarioId): array {
            $m = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
            }

            $s = DB::table('membresias.membresia_suspensiones')
                ->where('id', $suspensionId)
                ->where('membresia_id', $id)
                ->lockForUpdate()->first();
            if (! $s || $s->estado !== 'PROGRAMADA'
                || $s->tipo !== PoliticaCicloVidaMembresia::TIEMPO) {
                throw ValidationException::withMessages([
                    'suspension_id' => 'Solo se pueden cancelar solicitudes de vacaciones todavía programadas.',
                ]);
            }

            if (substr((string) $s->fecha_inicio, 0, 10) <= now('America/Guayaquil')->toDateString()) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => 'La programación ya llegó a su fecha de inicio; revisa el estado y el proceso de reactivación.',
                ]);
            }

            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::CANCELAR_PROGRAMACION,
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now('America/Guayaquil')->toDateString(),
                'motivo' => trim($motivo),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'movimiento_origen_id' => $s->movimiento_inicio_id,
                'detalle' => json_encode(['suspension_id' => $s->id,
                    'fecha_inicio' => $s->fecha_inicio, 'fecha_fin_prevista' => $s->fecha_fin_prevista],
                    JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('membresias.membresia_suspensiones')->where('id', $s->id)
                ->update(['estado' => 'CANCELADA', 'movimiento_fin_id' => $movimientoId,
                    'updated_at' => now()]);
            DB::table('membresias.membresia_movimientos')->where('id', $s->movimiento_inicio_id)
                ->update(['estado' => 'CANCELADO', 'updated_at' => now()]);

            return ['suspension_id' => $s->id, 'movimiento_id' => $movimientoId,
                'estado' => 'CANCELADA'];
        });
    }

    /**
     * Opera con fechas de Ecuador. Si el scheduler llega tarde no reconoce
     * automáticamente días en los que el cliente pudo haber entrenado.
     */
    public function procesarProgramadas(): array
    {
        $hoy = CarbonImmutable::today('America/Guayaquil')->toDateString();
        $ids = DB::table('membresias.membresia_suspensiones')
            ->where('estado', 'PROGRAMADA')
            ->where('tipo', PoliticaCicloVidaMembresia::TIEMPO)
            ->where('fecha_inicio', '<=', $hoy)
            ->orderBy('id')->pluck('id');
        $activadas = 0;
        $rechazadas = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $hoy, &$activadas, &$rechazadas): void {
                // Contrato ANTES que suspensión: mismo orden que las operaciones manuales.
                $ref = DB::table('membresias.membresia_suspensiones')->where('id', $id)
                    ->first(['membresia_id']);
                if (! $ref) return;
                $m = DB::table('membresias.membresias')
                    ->where('id', $ref->membresia_id)->lockForUpdate()->first();
                $s = DB::table('membresias.membresia_suspensiones')
                    ->where('id', $id)->lockForUpdate()->first();
                if (! $s || $s->estado !== 'PROGRAMADA' || ! $m) return;
                $otroVigente = DB::table('membresias.membresia_suspensiones')
                    ->where('membresia_id', $m->id)
                    ->where('estado', 'VIGENTE')->exists();
                $motivo = null;
                if (substr((string) $s->fecha_inicio, 0, 10) !== $hoy) {
                    $motivo = 'No fue posible activar en la fecha acordada; requiere nueva revisión.';
                } elseif ($m->estado !== 'ACTIVA' || $otroVigente
                    || substr((string) $m->fecha_fin, 0, 10) < substr((string) $s->fecha_fin_prevista, 0, 10)) {
                    $motivo = 'El contrato cambió o dejó de estar activo.';
                } else {
                    $motivo = $this->motivoBloqueoFinanciero((int) $m->id, $m);
                }
                if ($motivo) {
                    DB::table('membresias.membresia_suspensiones')->where('id', $s->id)
                        ->update(['estado' => 'NO_APLICADA', 'updated_at' => now()]);
                    $detalleOriginal = DB::table('membresias.membresia_movimientos')
                        ->where('id', $s->movimiento_inicio_id)->value('detalle');
                    $detalle = is_string($detalleOriginal)
                        ? (json_decode($detalleOriginal, true) ?: [])
                        : (array) ($detalleOriginal ?? []);
                    $detalle['motivo_no_aplicacion'] = $motivo;
                    $detalle['revisado_fecha'] = $hoy;
                    DB::table('membresias.membresia_movimientos')
                        ->where('id', $s->movimiento_inicio_id)
                        ->update(['estado' => 'NO_APLICADO',
                            'detalle' => json_encode($detalle, JSON_THROW_ON_ERROR),
                            'updated_at' => now()]);
                    $rechazadas++;
                    return;
                }
                DB::table('membresias.membresia_suspensiones')->where('id', $s->id)
                    ->update(['estado' => 'VIGENTE', 'dias_reconocidos' => (int) CarbonImmutable::parse($s->fecha_inicio)
                        ->diffInDays(CarbonImmutable::parse($s->fecha_fin_prevista)) + 1,
                        'fecha_fin_original' => $m->fecha_fin,
                        'fecha_fin_ajustada' => $this->politica->calcularFinAjustado(
                            (string) $m->fecha_fin,
                            (int) CarbonImmutable::parse($s->fecha_inicio)->diffInDays(CarbonImmutable::parse($s->fecha_fin_prevista)) + 1
                        ),
                        'updated_at' => now()]);
                DB::table('membresias.membresia_movimientos')->where('id', $s->movimiento_inicio_id)
                    ->update(['estado' => 'CONFIRMADO', 'updated_at' => now()]);
                DB::table('membresias.membresias')->where('id', $m->id)->update([
                    'estado' => 'CONGELADA',
                    'estado_id' => $this->estados->idPorValor('MEMBRESIA', 'CONGELADA'),
                    'updated_at' => now(),
                ]);
                $activadas++;
            });
        }

        // La ausencia no es una segunda congelación automática: al alcanzar
        // su fecha prevista, el contrato regresa al estado operativo acordado.
        $vencidas = DB::table('membresias.membresia_suspensiones')
            ->where('estado', 'VIGENTE')
            ->where('tipo', PoliticaCicloVidaMembresia::TIEMPO)
            ->where('fecha_fin_prevista', '<', $hoy)
            ->orderBy('id')->get(['id', 'membresia_id']);
        $reactivadas = 0;
        foreach ($vencidas as $s) {
            // Si el scheduler se ejecuta tarde, aplicar la fecha autorizada de
            // regreso y nunca reconocer los días posteriores como vacaciones.
            // El método revalida en transacción que aún existe suspensión vigente.
            $regresoAprobado = CarbonImmutable::parse($s->fecha_fin_prevista)
                ->addDay()->toDateString();
            try {
                $this->reactivar((int) $s->membresia_id,
                    'Finalización automática de vacaciones en la fecha autorizada.',
                    null, $regresoAprobado);
                $reactivadas++;
            } catch (ValidationException $error) {
                // Una reactivación manual concurrente pudo finalizar antes.
                // Cualquier otro problema de estado requiere revisión explícita.
                $continuaVigente = DB::table('membresias.membresia_suspensiones')
                    ->where('id', $s->id)->where('estado', 'VIGENTE')->exists();
                if ($continuaVigente) {
                    throw $error;
                }
            }
        }

        return ['programadas_revisadas' => $ids->count(), 'activadas' => $activadas,
            'no_aplicadas' => $rechazadas, 'reactivadas' => $reactivadas];
    }

    public function congelar(int $id, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $datos, $usuarioId): array {
            // Lock antes de recalcular y registrar para prevenir dos congelaciones simultáneas.
            $m = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
            }
            $previa = $this->previsualizar($id, $datos);
            // Revalidación obligatoria bajo bloqueo: no puede programarse una
            // congelación futura ni sortearse la prohibición financiera por API.
            if ($previa['inicio'] !== now('America/Guayaquil')->toDateString()) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => 'Solo es posible confirmar una congelación que inicia hoy.',
                ]);
            }
            if (! $previa['confirmable']) {
                throw ValidationException::withMessages([
                    'membresia_id' => $previa['motivo_bloqueo'] ?? 'La congelación requiere revisión financiera.',
                ]);
            }
            $codigoMotivo = $this->validarMotivoCatalogado($datos['motivo_codigo'] ?? null, 'CONGELAR');
            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::CONGELAR,
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now('America/Guayaquil')->toDateString(),
                'motivo' => trim($datos['motivo']),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'detalle' => json_encode($previa + ['motivo_codigo' => $codigoMotivo], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $suspensionId = DB::table('membresias.membresia_suspensiones')->insertGetId([
                'membresia_id' => $id,
                'movimiento_inicio_id' => $movimientoId,
                'tipo' => $previa['tipo'],
                'estado' => 'VIGENTE',
                'fecha_inicio' => $previa['inicio'],
                'fecha_fin_prevista' => $previa['fin_previsto'],
                'dias_reconocidos' => $previa['dias_previstos'],
                'fecha_fin_original' => $previa['fecha_fin_original'],
                'fecha_fin_ajustada' => $previa['fecha_fin_ajustada'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // El derecho deportivo queda suspendido, el ajuste de fecha se materializa al reactivar.
            DB::table('membresias.membresias')->where('id', $id)->update([
                'estado' => 'CONGELADA',
                'estado_id' => $this->estados->idPorValor('MEMBRESIA', 'CONGELADA'),
                'updated_at' => now(),
            ]);

            return ['suspension_id' => $suspensionId, 'movimiento_id' => $movimientoId] + $previa;
        });
    }

    /**
     * Previsualiza el regreso sin modificar contratos, cuotas ni historial.
     * La confirmación vuelve a calcularlo dentro del bloqueo transaccional.
     */
    public function previsualizarReactivacion(int $id): array
    {
        $m = DB::table('membresias.membresias')->where('id', $id)->first();
        $s = DB::table('membresias.membresia_suspensiones')
            ->where('membresia_id', $id)->where('estado', 'VIGENTE')
            ->orderByDesc('id')->first();
        $this->politica->validarReactivacion((string) ($m->estado ?? ''), $s !== null);
        $regreso = CarbonImmutable::today('America/Guayaquil');
        $calculoRegreso = $this->politica->calcularRegreso(
            (string) $s->fecha_inicio, (string) $s->fecha_fin_prevista,
            $regreso->toDateString()
        );
        $ultimoDia = CarbonImmutable::parse($calculoRegreso['fecha_fin_real']);
        $dias = $calculoRegreso['dias_reconocidos'];
        $impacto = $this->impactoFinanciero->previsualizar(
            $id, $s->fecha_inicio, $ultimoDia->toDateString(), $s->tipo
        );
        $bloqueo = $s->tipo === 'FACTURACION' && $impacto['requiere_revision_cartera']
            ? 'Existe deuda emitida que requiere conciliación de Cartera.' : null;

        return [
            'confirmable' => $bloqueo === null,
            'motivo_bloqueo' => $bloqueo,
            'suspension_id' => (int) $s->id,
            'tipo' => $s->tipo,
            'fecha_regreso' => $regreso->toDateString(),
            'fecha_fin_real' => $ultimoDia->toDateString(),
            'dias_reconocidos' => $dias,
            'fecha_fin_original' => substr((string) $s->fecha_fin_original, 0, 10),
            'fecha_fin_propuesta' => $this->politica->calcularFinAjustado(
                (string) $s->fecha_fin_original, $dias
            ),
            'impacto_financiero' => $impacto,
        ];
    }

    public function reactivar(int $id, string $motivo, ?int $usuarioId, ?string $fechaEfectiva = null, ?string $motivoCodigo = null): array
    {
        return DB::transaction(function () use ($id, $motivo, $usuarioId, $fechaEfectiva, $motivoCodigo): array {
            $m = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
            }
            $s = DB::table('membresias.membresia_suspensiones')
                ->where('membresia_id', $id)->where('estado', 'VIGENTE')->lockForUpdate()->first();
            $this->politica->validarReactivacion((string) $m->estado, $s !== null);
            // El scheduler puede reconciliar una finalización previamente
            // autorizada; la llamada humana siempre usa la fecha efectiva real.
            $hoy = $fechaEfectiva
                ? CarbonImmutable::parse($fechaEfectiva)
                : CarbonImmutable::today('America/Guayaquil');
            if ($fechaEfectiva !== null && ($usuarioId !== null
                || $s->tipo !== PoliticaCicloVidaMembresia::TIEMPO
                || $hoy->toDateString() !== CarbonImmutable::parse($s->fecha_fin_prevista)->addDay()->toDateString()
                || $hoy->greaterThan(CarbonImmutable::today('America/Guayaquil')))) {
                throw ValidationException::withMessages([
                    'fecha' => 'La fecha efectiva automática debe coincidir con el regreso autorizado de vacaciones.',
                ]);
            }
            $calculoRegreso = $this->politica->calcularRegreso(
                (string) $s->fecha_inicio, (string) $s->fecha_fin_prevista,
                $hoy->toDateString()
            );
            $finReal = CarbonImmutable::parse($calculoRegreso['fecha_fin_real']);
            $dias = $calculoRegreso['dias_reconocidos'];
            $finNuevo = $this->politica->calcularFinAjustado((string) $s->fecha_fin_original, $dias);
            $impacto = $this->impactoFinanciero->previsualizar(
                $id, $s->fecha_inicio, $finReal->toDateString(), (string) $s->tipo
            );
            if ($s->tipo === 'FACTURACION' && $impacto['requiere_revision_cartera']) {
                throw ValidationException::withMessages([
                    'membresia_id' => 'La conciliación financiera detectó deuda emitida durante la suspensión. Revisa Ventas y Cartera.',
                ]);
            }
            $codigoReactivacion = $this->validarMotivoCatalogado($motivoCodigo, 'REACTIVAR');
            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::REACTIVAR,
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => $hoy->toDateString(),
                'motivo' => trim($motivo),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'movimiento_origen_id' => $s->movimiento_inicio_id,
                'detalle' => json_encode([
                    'fecha_regreso' => $hoy->toDateString(),
                    'fecha_fin_real' => $finReal->toDateString(),
                    'dias_reconocidos' => $dias,
                    'fecha_fin_anterior' => $s->fecha_fin_original,
                    'fecha_fin_nueva' => $finNuevo,
                    'motivo_codigo' => $codigoReactivacion,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('membresias.membresia_suspensiones')->where('id', $s->id)->update([
                'estado' => 'FINALIZADA', 'fecha_fin_real' => $finReal->toDateString(),
                'dias_reconocidos' => $dias, 'fecha_fin_ajustada' => $finNuevo,
                'movimiento_fin_id' => $movimientoId, 'updated_at' => now(),
            ]);
            DB::table('membresias.membresias')->where('id', $id)->update([
                'estado' => 'ACTIVA',
                'estado_id' => $this->estados->idPorValor('MEMBRESIA', 'ACTIVA'),
                'fecha_fin' => $finNuevo,
                'updated_at' => now(),
            ]);
            // Desplazar cada tramo pendiente de servicio, sin sobreescribir
            // todas las fechas finales con una misma fecha.
            $periodos = DB::table('membresias.membresia_periodos')
                ->where('membresia_id', $id)->orderBy('numero_periodo')
                ->lockForUpdate()->get();
            foreach ($periodos as $periodo) {
                $cambios = [];
                if (substr((string) $periodo->fecha_inicio, 0, 10) >= $s->fecha_inicio) {
                    $cambios['fecha_inicio'] = CarbonImmutable::parse($periodo->fecha_inicio)->addDays($dias)->toDateString();
                }
                if (substr((string) $periodo->fecha_fin, 0, 10) >= $s->fecha_inicio) {
                    $cambios['fecha_fin'] = CarbonImmutable::parse($periodo->fecha_fin)->addDays($dias)->toDateString();
                }
                if ($cambios) {
                    DB::table('membresias.membresia_periodos')->where('id', $periodo->id)
                        ->update($cambios + ['updated_at' => now()]);
                }
            }
            // Únicamente cuotas no emitidas pueden moverse; una venta con
            // pago parcial o completo queda INTACTA y permanece en Cartera.
            if ($s->tipo === 'FACTURACION') {
                foreach ($impacto['cuotas'] as $cuota) {
                    if ($cuota['accion'] !== 'REPROGRAMACION_PROPUESTA') {
                        continue;
                    }
                    $cuotaActual = DB::table('membresias.membresia_cuotas')
                        ->where('id', $cuota['cuota_id'])->lockForUpdate()->first();
                    if (! $cuotaActual || $cuotaActual->estado !== 'PROGRAMADA'
                        || $cuotaActual->venta_id !== null
                        || substr((string) $cuotaActual->fecha_programada_emision, 0, 10) !== $cuota['emision_anterior']
                        || substr((string) $cuotaActual->fecha_vencimiento, 0, 10) !== $cuota['vencimiento_anterior']) {
                        throw ValidationException::withMessages([
                            'cuotas' => 'El calendario cambió durante la suspensión; es necesario conciliarlo antes de reactivar.',
                        ]);
                    }
                    DB::table('membresias.membresia_cuotas')->where('id', $cuota['cuota_id'])->update([
                        'fecha_programada_emision' => $cuota['emision_propuesta'],
                        'fecha_vencimiento' => $cuota['vencimiento_propuesto'],
                        'updated_at' => now(),
                    ]);
                    DB::table('membresias.membresia_reprogramaciones')->insert([
                        'membresia_id' => $id,
                        'movimiento_id' => $movimientoId,
                        'cuota_id' => $cuota['cuota_id'],
                        'emision_anterior' => $cuota['emision_anterior'],
                        'emision_nueva' => $cuota['emision_propuesta'],
                        'vencimiento_anterior' => $cuota['vencimiento_anterior'],
                        'vencimiento_nuevo' => $cuota['vencimiento_propuesto'],
                        'estado' => 'APLICADA',
                        'motivo' => trim($motivo),
                        'impacto_financiero' => json_encode($cuota, JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('membresias.membresia_reprogramaciones')->insert([
                'membresia_id' => $id, 'movimiento_id' => $movimientoId,
                'vigencia_fin_anterior' => $s->fecha_fin_original,
                'vigencia_fin_nueva' => $finNuevo, 'estado' => 'APLICADA',
                'motivo' => trim($motivo), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return [
                'membresia_id' => $id, 'suspension_id' => $s->id,
                'fecha_regreso' => $hoy->toDateString(),
                'fecha_fin_real' => $finReal->toDateString(),
                'dias_reconocidos' => $dias,
                'fecha_fin_original' => substr((string) $s->fecha_fin_original, 0, 10),
                'fecha_fin' => $finNuevo,
            ];
        });
    }
}
