<?php

namespace App\Services\Gimnasio;

use App\Services\Configuracion\EstadoCatalogoServicio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fase 2: operación de congelación de TIEMPO sobre contrato completamente pagado.
 * FACTURACION, cobros futuros, cierre y recontratación esperan fases 3/4.
 * Operaciones serializadas por lockForUpdate en membresía.
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
     * Solo contrato con un período pagado, sin renovación/cobro automático.
     * Nunca ajustar un calendario económico futuro sin conciliación.
     */
    private function motivoBloqueoFinanciero(int $id, object $m): ?string
    {
        if ((bool) ($m->generar_venta_automatica ?? false)
            || (bool) ($m->renovacion_automatica ?? false)) {
            return 'Desactiva los cobros recurrentes mediante el proceso financiero de Fase 3.';
        }

        $periodos = DB::table('membresias.membresia_periodos')->where('membresia_id', $id)->get();
        if ($periodos->count() !== 1) {
            return 'La congelación de contratos con varios períodos requiere reprogramación financiera (Fase 3).';
        }

        if (DB::table('membresias.membresia_cuotas')->where('membresia_id', $id)
            ->where('estado', 'PROGRAMADA')->exists()) {
            return 'Existen cuotas programadas; su suspensión corresponde a la Fase 3.';
        }
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

    public function previsualizar(int $id, array $datos): array
    {
        $m = DB::table('membresias.membresias')->where('id', $id)->first();
        if (! $m) {
            throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
        }
        $tipo = strtoupper((string) $datos['tipo']);
        if (! in_array($tipo, [PoliticaCicloVidaMembresia::TIEMPO, PoliticaCicloVidaMembresia::FACTURACION], true)) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de congelación no reconocido.']);
        }
        $existe = DB::table('membresias.membresia_suspensiones')
            ->where('membresia_id', $id)->whereIn('estado', ['PROGRAMADA', 'VIGENTE'])->exists();
        $this->politica->validarCongelacion((string) $m->estado, $tipo, $existe, false);
        $intervalo = $this->politica->validarIntervalo($datos['fecha_inicio'], $datos['fecha_fin']);
        $hoy = now()->toDateString();
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
        $inicioFuturo = $intervalo['fecha_inicio'] !== $hoy;
        $motivosBloqueo = array_values(array_filter([
            $inicioFuturo ? 'La congelación futura solo puede simularse en Fase 2; todavía no puede programarse.' : null,
            $bloqueo,
        ]));
        // La simulación no cambia datos. Un contrato puede ser simulable sin ser confirmable.
        return [
            'confirmable' => ! $inicioFuturo && $bloqueo === null,
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
            if ($previa['inicio'] !== now()->toDateString()) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => 'Solo es posible confirmar una congelación que inicia hoy.',
                ]);
            }
            if (! $previa['confirmable']) {
                throw ValidationException::withMessages([
                    'membresia_id' => $previa['motivo_bloqueo'] ?? 'La congelación requiere revisión financiera.',
                ]);
            }
            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::CONGELAR,
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => now()->toDateString(),
                'motivo' => trim($datos['motivo']),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'detalle' => json_encode($previa, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $suspensionId = DB::table('membresias.membresia_suspensiones')->insertGetId([
                'membresia_id' => $id,
                'movimiento_inicio_id' => $movimientoId,
                'tipo' => 'TIEMPO',
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

    public function reactivar(int $id, string $motivo, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $motivo, $usuarioId): array {
            $m = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
            }
            $s = DB::table('membresias.membresia_suspensiones')
                ->where('membresia_id', $id)->where('estado', 'VIGENTE')->lockForUpdate()->first();
            $this->politica->validarReactivacion((string) $m->estado, $s !== null);
            $hoy = CarbonImmutable::today();
            $inicio = CarbonImmutable::parse($s->fecha_inicio);
            if ($hoy->lessThanOrEqualTo($inicio)) {
                throw ValidationException::withMessages(['fecha' => 'La reactivación exige al menos un día completo de suspensión.']);
            }
            $finReal = $hoy->subDay();
            $finPrevisto = CarbonImmutable::parse($s->fecha_fin_prevista);
            if ($finReal->greaterThan($finPrevisto)) {
                throw ValidationException::withMessages(['fecha' => 'La suspensión excedió la fecha prevista; requiere revisión administrativa antes de reactivar.']);
            }
            $dias = (int) $inicio->diffInDays($finReal) + 1;
            $finNuevo = $this->politica->calcularFinAjustado((string) $s->fecha_fin_original, $dias);
            $movimientoId = DB::table('membresias.membresia_movimientos')->insertGetId([
                'membresia_id' => $id,
                'tipo' => PoliticaCicloVidaMembresia::REACTIVAR,
                'estado' => 'CONFIRMADO',
                'fecha_efectiva' => $hoy->toDateString(),
                'motivo' => trim($motivo),
                'usuario_id' => $usuarioId,
                'autorizado_por' => $usuarioId,
                'movimiento_origen_id' => $s->movimiento_inicio_id,
                'detalle' => json_encode(['dias_reconocidos' => $dias, 'fecha_fin_anterior' => $s->fecha_fin_original,
                    'fecha_fin_nueva' => $finNuevo], JSON_THROW_ON_ERROR),
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
            DB::table('membresias.membresia_periodos')->where('membresia_id', $id)->update([
                'fecha_fin' => $finNuevo, 'updated_at' => now(),
            ]);
            DB::table('membresias.membresia_reprogramaciones')->insert([
                'membresia_id' => $id, 'movimiento_id' => $movimientoId,
                'vigencia_fin_anterior' => $s->fecha_fin_original,
                'vigencia_fin_nueva' => $finNuevo, 'estado' => 'APLICADA',
                'motivo' => trim($motivo), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['membresia_id' => $id, 'suspension_id' => $s->id,
                'dias_reconocidos' => $dias, 'fecha_fin' => $finNuevo];
        });
    }
}
