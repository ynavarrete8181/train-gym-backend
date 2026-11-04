<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\MembresiaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MisMembresiasController extends Controller
{
    public function __construct(private readonly MembresiaServicio $membresias) {}

    public function __invoke(Request $request): JsonResponse
    {
        $deportista = DB::table('clientes.deportistas')
            ->where('usuario_id', $request->user()->id)
            ->first(['id']);

        if (! $deportista) {
            return ApiResponse::exito('No existe una ficha de deportista vinculada a esta cuenta.', []);
        }

        $ids = DB::table('membresias.membresias')
            ->where('deportista_id', $deportista->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        $datos = $ids->map(function ($id): ?array {
            $m = $this->membresias->obtenerMembresiaConRelaciones((int) $id);
            if (! $m) return null;

            // El origen contractual procede del snapshot histórico y no de
            // la fecha_fin actual (que pudo extenderse por reactivaciones).
            $contratacion = DB::table('membresias.membresia_condiciones_historial')
                ->where('membresia_id', $m->id)->where('accion', 'CONTRATACION')
                ->orderBy('version')->first(['condiciones']);
            $original = $contratacion
                ? (json_decode((string) $contratacion->condiciones, true) ?: null) : null;
            $fechaFinOriginal = is_array($original)
                ? ($original['fecha_fin'] ?? null) : null;
            $cuotas = collect($m->cuotas ?? []);
            $pagadoConfirmado = round((float) $cuotas->sum('monto_pagado'), 2);
            $saldoEmitido = round((float) $cuotas
                ->filter(fn ($c) => $c->venta_id && $c->venta_estado !== 'ANULADA')
                ->sum(fn ($c) => max(0, (float) $c->monto - (float) $c->monto_pagado)), 2);
            $hoy = now('America/Guayaquil')->toDateString();
            $periodos = collect($m->periodos ?? []);
            $periodoVigente = $periodos->first(fn ($periodo) =>
                substr((string) $periodo->fecha_inicio, 0, 10) <= $hoy
                && substr((string) $periodo->fecha_fin, 0, 10) >= $hoy
            );
            $periodosFuturos = $periodos
                ->filter(fn ($periodo) => substr((string) $periodo->fecha_inicio, 0, 10) > $hoy)
                ->sortBy('numero_periodo')
                ->map(fn ($periodo) => [
                    'id' => $periodo->id,
                    'numero_periodo' => $periodo->numero_periodo,
                    'fecha_inicio' => $periodo->fecha_inicio,
                    'fecha_fin' => $periodo->fecha_fin,
                    'estado' => $periodo->estado,
                    'venta_estado' => $periodo->venta_estado,
                    'renovacion_manual' => (bool) ($periodo->renovacion_manual ?? false),
                ])->values()->all();
            $periodosRenovados = $periodos
                ->filter(fn ($periodo) => (bool) ($periodo->renovacion_manual ?? false))
                ->map(fn ($periodo) => [
                    'id' => $periodo->id,
                    'numero_periodo' => $periodo->numero_periodo,
                    'fecha_inicio' => $periodo->fecha_inicio,
                    'fecha_fin' => $periodo->fecha_fin,
                    'estado' => $periodo->estado,
                    'venta_estado' => $periodo->venta_estado,
                    'precio' => $periodo->precio,
                ])->values()->all();

            // Vista informativa en tiempo real: no confundir ACTIVA con
            // un nuevo período que inició sin venta totalmente pagada.
            // Las restricciones operativas de ingreso siguen en Laravel.
            $renovacionImpaga = DB::table('membresias.membresia_periodos as pr')
                ->leftJoin('ventas.ventas as vr', 'vr.id', '=', 'pr.venta_id')
                ->where('pr.membresia_id', $m->id)
                ->where('pr.renovacion_manual', true)
                ->where('pr.fecha_inicio', '<=', now('America/Guayaquil')->toDateString())
                ->where(function ($q): void {
                    $q->whereNull('pr.venta_id')->orWhere('vr.estado', '!=', 'PAGADA');
                })->exists();

            return [
                'id' => $m->id,
                'codigo_contrato' => $m->codigo_contrato,
                'plan_nombre' => $m->plan_nombre,
                'modalidad_nombre' => $m->modalidad_nombre,
                'tipo_producto' => $m->tipo_producto,
                'tipo_cobro' => $m->tipo_cobro,
                'cantidad_periodos_contratados' => $m->cantidad_periodos_contratados ?? null,
                'estado' => $m->estado,
                'estado_consulta' => $renovacionImpaga && $m->estado === 'ACTIVA'
                    ? 'PENDIENTE_PAGO' : $m->estado,
                'renovacion_iniciada_impaga' => $renovacionImpaga,
                'estado_nombre' => $m->estado_nombre,
                'fecha_inicio' => $m->fecha_inicio,
                'fecha_fin' => $m->fecha_fin,
                'fecha_fin_original' => $fechaFinOriginal,
                'fecha_fin_vigente' => $m->fecha_fin,
                'total_pagado_confirmado' => $pagadoConfirmado,
                'saldo_ventas_emitidas' => $saldoEmitido,
                'periodos_renovados' => $periodosRenovados,
                'periodo_vigente' => $periodoVigente,
                'periodos_futuros' => $periodosFuturos,
                'precio_aplicado' => $m->precio_aplicado,
                'sede_nombre' => $m->sede_nombre,
                'sedes_habilitadas' => $m->sedes_habilitadas,
                'asignaciones_entrenador' => $m->asignaciones_entrenador,
                'modalidad_dias_por_semana' => $m->modalidad_dias_por_semana,
                'modalidad_usos_por_semana' => $m->modalidad_usos_por_semana,
                'modalidad_uso_ilimitado' => $m->modalidad_uso_ilimitado,
                'modalidad_tipo_duracion' => $m->modalidad_tipo_duracion,
                'modalidad_duracion' => $m->modalidad_duracion,
                'renovable' => $m->renovable,
                'renovacion_automatica' => $m->renovacion_automatica ?? null,
                'requiere_pago' => $m->requiere_pago,
                'requiere_entrenador' => $m->requiere_entrenador,
                'proxima_fecha_cobro' => $m->proxima_fecha_cobro ?? null,
                'venta_numero' => $m->venta_numero,
                'venta_estado' => $m->venta_estado,
                'venta_total' => $m->venta_total,
                'periodos' => $m->periodos,
                'cuotas' => $m->cuotas,
                'reprogramaciones' => DB::table('membresias.membresia_reprogramaciones')
                    ->where('membresia_id', $m->id)
                    ->where('estado', 'APLICADA')
                    ->orderByDesc('id')->limit(20)->get([
                        'id', 'cuota_id', 'emision_anterior', 'emision_nueva',
                        'vencimiento_anterior', 'vencimiento_nuevo',
                        'vigencia_fin_anterior', 'vigencia_fin_nueva', 'estado',
                    ]),
                'suspensiones' => DB::table('membresias.membresia_suspensiones')
                    ->where('membresia_id', $m->id)
                    ->orderByDesc('id')->limit(10)->get([
                        'id', 'tipo', 'estado', 'fecha_inicio', 'fecha_fin_prevista',
                        'fecha_fin_real', 'dias_reconocidos', 'fecha_fin_original', 'fecha_fin_ajustada',
                    ]),
            ];
        })->filter()->values()->all();

        return ApiResponse::exito('Asignaciones de membresía consultadas.', $datos);
    }
}
