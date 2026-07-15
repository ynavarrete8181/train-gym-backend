<?php

namespace App\Queries\Entrenamiento;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlanEntrenamientoQuery
{
    private function alcanceSelectSql(): string
    {
        return Schema::hasColumn('entrenamiento.planes', 'alcance')
            ? "p.alcance"
            : "'GRUPAL' as alcance";
    }

    public function listarPlanes(array $filtros = []): array
    {
        $alcanceSql = $this->alcanceSelectSql();

        $diasSubquery = DB::table('entrenamiento.plan_dias')
            ->selectRaw('plan_id, COUNT(*) AS total_dias, MAX(semana) AS total_semanas')
            ->groupBy('plan_id');

        $query = DB::table('entrenamiento.planes as p')
            ->leftJoin('core.personas as c', 'c.id', '=', 'p.persona_id')
            ->leftJoinSub($diasSubquery, 'pd', function ($join) {
                $join->on('pd.plan_id', '=', 'p.id');
            })
            ->selectRaw("
                p.id,
                p.persona_id,
                p.nombre,
                p.tipo,
                {$alcanceSql},
                p.estructura,
                p.objetivo,
                p.fecha_inicio,
                p.fecha_fin,
                p.estado,
                p.observaciones,
                c.numero_identificacion as cedula,
                c.nombres,
                c.apellidos,
                COALESCE(pd.total_dias, 0) as total_dias,
                COALESCE(pd.total_semanas, 0) as total_semanas
            ");

        if (!empty($filtros['persona_id'])) {
            $query->where('p.persona_id', (int) $filtros['persona_id']);
        }

        if (!empty($filtros['estado'])) {
            $query->where('p.estado', $filtros['estado']);
        }

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($nested) use ($buscar) {
                $nested->where('p.nombre', 'like', $buscar)
                    ->orWhere('p.tipo', 'like', $buscar)
                    ->orWhere('p.estructura', 'like', $buscar)
                    ->orWhere('p.objetivo', 'like', $buscar)
                    ->orWhere('p.estado', 'like', $buscar)
                    ->orWhere('c.numero_identificacion', 'like', $buscar)
                    ->orWhere('c.nombres', 'like', $buscar)
                    ->orWhere('c.apellidos', 'like', $buscar);
            });
        }

        return $query->orderByDesc('p.fecha_inicio')
            ->orderByDesc('p.id')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'persona_id' => $item->persona_id !== null ? (int) $item->persona_id : null,
                'nombre' => $item->nombre,
                'tipo' => $item->tipo,
                'alcance' => $item->alcance,
                'estructura' => $item->estructura,
                'objetivo' => $item->objetivo,
                'fecha_inicio' => $item->fecha_inicio,
                'fecha_fin' => $item->fecha_fin,
                'estado' => $item->estado,
                'observaciones' => $item->observaciones,
                'cedula' => $item->cedula,
                'nombre_completo' => trim(($item->nombres ?? '') . ' ' . ($item->apellidos ?? '')) ?: null,
                'total_dias' => (int) $item->total_dias,
                'total_semanas' => (int) $item->total_semanas,
            ])
            ->all();
    }

    public function obtenerDetallePlan(int $planId): ?array
    {
        $alcanceSql = $this->alcanceSelectSql();

        $plan = DB::table('entrenamiento.planes as p')
            ->leftJoin('core.personas as c', 'c.id', '=', 'p.persona_id')
            ->selectRaw("
                p.id,
                p.persona_id,
                p.nombre,
                p.tipo,
                {$alcanceSql},
                p.estructura,
                p.objetivo,
                p.fecha_inicio,
                p.fecha_fin,
                p.estado,
                p.observaciones,
                c.numero_identificacion as cedula,
                c.nombres,
                c.apellidos
            ")
            ->where('p.id', $planId)
            ->first();

        if (!$plan) {
            return null;
        }

        $dias = DB::table('entrenamiento.plan_dias')
            ->where('plan_id', $planId)
            ->orderBy('semana')
            ->orderByRaw("
                CASE dia
                    WHEN 'LUNES' THEN 1
                    WHEN 'MARTES' THEN 2
                    WHEN 'MIERCOLES' THEN 3
                    WHEN 'JUEVES' THEN 4
                    WHEN 'VIERNES' THEN 5
                    WHEN 'SABADO' THEN 6
                    WHEN 'DOMINGO' THEN 7
                    ELSE 8
                END
            ")
            ->get();

        $diaIds = $dias->pluck('id')->map(fn ($id) => (int) $id)->all();

        $bloques = empty($diaIds)
            ? collect()
            : DB::table('entrenamiento.plan_bloques')
                ->whereIn('plan_dia_id', $diaIds)
                ->orderBy('orden')
                ->orderBy('id')
                ->get();

        $bloqueIds = $bloques->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ejercicios = empty($bloqueIds)
            ? collect()
            : DB::table('entrenamiento.plan_ejercicios as pe')
                ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'pe.ejercicio_id')
                ->leftJoin('entrenamiento.rm_registros as rr', 'rr.id', '=', 'pe.rm_registro_id')
                ->whereIn('pe.plan_bloque_id', $bloqueIds)
                ->selectRaw("
                    pe.*,
                    e.nombre as ejercicio_nombre,
                    rr.persona_id as rm_registro_persona_id,
                    rr.rm_estimado as rm_registro_valor
                ")
                ->orderBy('pe.orden')
                ->orderBy('pe.id')
                ->get();

        $ejercicioIds = $ejercicios->pluck('id')->map(fn ($id) => (int) $id)->all();

        $series = empty($ejercicioIds)
            ? collect()
            : DB::table('entrenamiento.plan_ejercicio_series')
                ->whereIn('plan_ejercicio_id', $ejercicioIds)
                ->orderBy('numero_serie')
                ->orderBy('id')
                ->get();

        $transferencias = empty($ejercicioIds)
            ? collect()
            : DB::table('entrenamiento.plan_ejercicio_transferencias as pt')
                ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'pt.ejercicio_id')
                ->whereIn('pt.plan_ejercicio_id', $ejercicioIds)
                ->selectRaw('pt.*, e.nombre as ejercicio_nombre')
                ->orderBy('pt.orden')
                ->orderBy('pt.id')
                ->get();

        $transferenciaIds = $transferencias->pluck('id')->map(fn ($id) => (int) $id)->all();

        $transferSeries = empty($transferenciaIds)
            ? collect()
            : DB::table('entrenamiento.plan_transferencia_series')
                ->whereIn('transferencia_id', $transferenciaIds)
                ->orderBy('numero_serie')
                ->orderBy('id')
                ->get();

        return [
            'plan' => [
                'id' => (int) $plan->id,
                'persona_id' => $plan->persona_id !== null ? (int) $plan->persona_id : null,
                'nombre' => $plan->nombre,
                'tipo' => $plan->tipo,
                'alcance' => $plan->alcance,
                'estructura' => $plan->estructura,
                'objetivo' => $plan->objetivo,
                'fecha_inicio' => $plan->fecha_inicio,
                'fecha_fin' => $plan->fecha_fin,
                'estado' => $plan->estado,
                'observaciones' => $plan->observaciones,
                'cedula' => $plan->cedula,
                'nombre_completo' => trim(($plan->nombres ?? '') . ' ' . ($plan->apellidos ?? '')) ?: null,
            ],
            'dias' => $this->mapDias($dias, $bloques, $ejercicios, $series, $transferencias, $transferSeries),
        ];
    }

    public function listarAsignacionesPlan(int $planId): array
    {
        return DB::table('entrenamiento.plan_asignaciones as pa')
            ->leftJoin('core.personas as p', 'p.id', '=', 'pa.persona_id')
            ->leftJoin('socios.socios as s', 's.persona_id', '=', 'p.id')
            ->where('pa.plan_id', $planId)
            ->selectRaw("
                pa.id,
                pa.plan_id,
                pa.alcance,
                pa.persona_id,
                pa.nombre_grupo,
                pa.fecha_inicio,
                pa.fecha_fin,
                pa.estado,
                pa.observaciones,
                s.codigo_socio,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos
            ")
            ->orderByDesc('pa.id')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'plan_id' => (int) $item->plan_id,
                'alcance' => $item->alcance,
                'persona_id' => $item->persona_id ? (int) $item->persona_id : null,
                'nombre_grupo' => $item->nombre_grupo,
                'fecha_inicio' => $item->fecha_inicio,
                'fecha_fin' => $item->fecha_fin,
                'estado' => $item->estado,
                'observaciones' => $item->observaciones,
                'codigo_socio' => $item->codigo_socio,
                'cedula' => $item->cedula,
                'nombre_completo' => trim(($item->nombres ?? '') . ' ' . ($item->apellidos ?? '')) ?: null,
            ])
            ->all();
    }

    public function listarPersonasDisponibles(): array
    {
        return DB::table('core.personas as p')
            ->selectRaw("
                null as socio_id,
                null as codigo_socio,
                p.id as persona_id,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos
            ")
            ->orderBy('p.nombres')
            ->orderBy('p.apellidos')
            ->get()
            ->map(fn ($item) => [
                'socio_id' => null,
                'codigo_socio' => null,
                'persona_id' => (int) $item->persona_id,
                'cedula' => $item->cedula,
                'nombre_completo' => trim(($item->nombres ?? '') . ' ' . ($item->apellidos ?? '')),
            ])
            ->all();
    }

    private function mapDias(
        Collection $dias,
        Collection $bloques,
        Collection $ejercicios,
        Collection $series,
        Collection $transferencias,
        Collection $transferSeries
    ): array {
        return $dias->map(function ($dia) use ($bloques, $ejercicios, $series, $transferencias, $transferSeries) {
            $bloquesDelDia = $bloques
                ->where('plan_dia_id', $dia->id)
                ->values()
                ->map(function ($bloque) use ($ejercicios, $series, $transferencias, $transferSeries) {
                    $ejerciciosDelBloque = $ejercicios
                        ->where('plan_bloque_id', $bloque->id)
                        ->values()
                        ->map(function ($ejercicio) use ($series, $transferencias, $transferSeries) {
                            $seriesEjercicio = $series
                                ->where('plan_ejercicio_id', $ejercicio->id)
                                ->values()
                                ->map(fn ($serie) => [
                                    'id' => (int) $serie->id,
                                    'numero_serie' => (int) $serie->numero_serie,
                                    'tipo_carga' => $serie->tipo_carga,
                                    'porcentaje_rm' => $serie->porcentaje_rm !== null ? (float) $serie->porcentaje_rm : null,
                                    'carga_fija' => $serie->carga_fija !== null ? (float) $serie->carga_fija : null,
                                    'unidad_carga' => $serie->unidad_carga,
                                    'repeticiones' => $serie->repeticiones,
                                    'tiempo_segundos' => $serie->tiempo_segundos !== null ? (int) $serie->tiempo_segundos : null,
                                    'distancia_metros' => $serie->distancia_metros !== null ? (float) $serie->distancia_metros : null,
                                    'rpe' => $serie->rpe !== null ? (float) $serie->rpe : null,
                                    'descanso_segundos' => $serie->descanso_segundos !== null ? (int) $serie->descanso_segundos : null,
                                    'tempo' => $serie->tempo,
                                    'observaciones' => $serie->observaciones,
                                ])
                                ->all();

                            $transferenciasEjercicio = $transferencias
                                ->where('plan_ejercicio_id', $ejercicio->id)
                                ->values()
                                ->map(function ($transferencia) use ($transferSeries) {
                                    $seriesTransferencia = $transferSeries
                                        ->where('transferencia_id', $transferencia->id)
                                        ->values()
                                        ->map(fn ($serie) => [
                                            'id' => (int) $serie->id,
                                            'numero_serie' => (int) $serie->numero_serie,
                                            'tipo_carga' => $serie->tipo_carga,
                                            'porcentaje_rm' => $serie->porcentaje_rm !== null ? (float) $serie->porcentaje_rm : null,
                                            'carga_fija' => $serie->carga_fija !== null ? (float) $serie->carga_fija : null,
                                            'unidad_carga' => $serie->unidad_carga,
                                            'repeticiones' => $serie->repeticiones,
                                            'tiempo_segundos' => $serie->tiempo_segundos !== null ? (int) $serie->tiempo_segundos : null,
                                            'distancia_metros' => $serie->distancia_metros !== null ? (float) $serie->distancia_metros : null,
                                            'rpe' => $serie->rpe !== null ? (float) $serie->rpe : null,
                                            'observaciones' => $serie->observaciones,
                                        ])
                                        ->all();

                                    return [
                                        'id' => (int) $transferencia->id,
                                        'ejercicio_id' => (int) $transferencia->ejercicio_id,
                                        'ejercicio_nombre' => $transferencia->ejercicio_nombre,
                                        'orden' => (int) $transferencia->orden,
                                        'modo_aplicacion' => $transferencia->modo_aplicacion,
                                        'observaciones' => $transferencia->observaciones,
                                        'series' => $seriesTransferencia,
                                    ];
                                })
                                ->all();

                            return [
                                'id' => (int) $ejercicio->id,
                                'ejercicio_id' => (int) $ejercicio->ejercicio_id,
                                'ejercicio_nombre' => $ejercicio->ejercicio_nombre,
                                'orden' => (int) $ejercicio->orden,
                                'lado' => $ejercicio->lado,
                                'observaciones' => $ejercicio->observaciones,
                                'usa_rm' => (bool) $ejercicio->usa_rm,
                                'rm_referencia' => $ejercicio->rm_referencia !== null ? (float) $ejercicio->rm_referencia : null,
                                'rm_registro_id' => $ejercicio->rm_registro_id !== null ? (int) $ejercicio->rm_registro_id : null,
                                'rm_registro_persona_id' => $ejercicio->rm_registro_persona_id !== null ? (int) $ejercicio->rm_registro_persona_id : null,
                                'rm_registro_valor' => $ejercicio->rm_registro_valor !== null ? (float) $ejercicio->rm_registro_valor : null,
                                'modo_prescripcion' => $ejercicio->modo_prescripcion,
                                'descanso_segundos' => $ejercicio->descanso_segundos !== null ? (int) $ejercicio->descanso_segundos : null,
                                'tempo' => $ejercicio->tempo,
                                'rpe_objetivo' => $ejercicio->rpe_objetivo !== null ? (float) $ejercicio->rpe_objetivo : null,
                                'series' => $seriesEjercicio,
                                'transferencias' => $transferenciasEjercicio,
                            ];
                        })
                        ->all();

                    return [
                        'id' => (int) $bloque->id,
                        'nombre' => $bloque->nombre,
                        'tipo_bloque' => $bloque->tipo_bloque,
                        'orden' => (int) $bloque->orden,
                        'observaciones' => $bloque->observaciones,
                        'ejercicios' => $ejerciciosDelBloque,
                    ];
                })
                ->all();

            return [
                'id' => (int) $dia->id,
                'semana' => (int) $dia->semana,
                'dia' => $dia->dia,
                'nombre_sesion' => $dia->nombre_sesion,
                'observaciones' => $dia->observaciones,
                'bloques' => $bloquesDelDia,
            ];
        })->all();
    }
}
