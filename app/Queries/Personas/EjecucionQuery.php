<?php

namespace App\Queries\Personas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EjecucionQuery
{
    public function listarPlanesDisponibles(): array
    {
        $hasAssignments = Schema::hasTable('entrenamiento.plan_asignaciones');
        $hasAlcance = Schema::hasColumn('entrenamiento.planes', 'alcance');

        $query = DB::table('entrenamiento.planes as p');

        if ($hasAssignments) {
            $latestAssignments = DB::table('entrenamiento.plan_asignaciones as pa')
                ->selectRaw('MAX(pa.id) as id, pa.plan_id')
                ->groupBy('pa.plan_id');

            $query
                ->joinSub($latestAssignments, 'pa_latest', function ($join) {
                    $join->on('pa_latest.plan_id', '=', 'p.id');
                })
                ->join('entrenamiento.plan_asignaciones as pa', 'pa.id', '=', 'pa_latest.id')
                ->leftJoin('core.personas as person_dest', 'person_dest.id', '=', 'pa.persona_id')
                ->leftJoin('socios.socios as s', 's.persona_id', '=', 'person_dest.id');
        } else {
            $query->leftJoin('core.personas as person_dest', 'person_dest.id', '=', 'p.persona_id');
        }

        return $query
            ->where('p.estado', '<>', 'FINALIZADO')
            ->selectRaw("
                p.id,
                p.nombre,
                p.objetivo,
                p.estado,
                " . ($hasAlcance ? "p.alcance" : "'GRUPAL' as alcance") . ",
                " . ($hasAssignments ? "pa.nombre_grupo" : "NULL as nombre_grupo") . ",
                person_dest.numero_identificacion as cedula,
                person_dest.nombres,
                person_dest.apellidos,
                " . ($hasAssignments ? "s.codigo_socio" : "NULL as codigo_socio") . "
            ")
            ->orderByDesc('p.id')
            ->get()
            ->map(function ($item) {
                $nombreCompleto = trim(($item->nombres ?? '') . ' ' . ($item->apellidos ?? ''));
                $destino = ($item->alcance ?? 'GRUPAL') === 'INDIVIDUAL'
                    ? ($nombreCompleto ?: 'Cliente asignado')
                    : ($item->nombre_grupo ?: 'Grupo asignado');

                return [
                    'id' => (int) $item->id,
                    'persona_id' => null,
                    'nombre' => $item->nombre,
                    'objetivo' => $item->objetivo,
                    'estado' => $item->estado,
                    'alcance' => $item->alcance ?? 'GRUPAL',
                    'cedula' => $item->cedula,
                    'nombre_completo' => $nombreCompleto ?: null,
                    'destino' => $destino,
                    'codigo_socio' => $item->codigo_socio,
                ];
            })
            ->all();
    }

    public function listarDetallePorPlanYFecha(int $planId, string $fecha): array
    {
        $hasAssignments = Schema::hasTable('entrenamiento.plan_asignaciones');
        $hasAlcance = Schema::hasColumn('entrenamiento.planes', 'alcance');

        $planQuery = DB::table('entrenamiento.planes as p');

        if ($hasAssignments) {
            $latestAssignments = DB::table('entrenamiento.plan_asignaciones as pa')
                ->selectRaw('MAX(pa.id) as id, pa.plan_id')
                ->groupBy('pa.plan_id');

            $planQuery
                ->leftJoinSub($latestAssignments, 'pa_latest', function ($join) {
                    $join->on('pa_latest.plan_id', '=', 'p.id');
                })
                ->leftJoin('entrenamiento.plan_asignaciones as pa', 'pa.id', '=', 'pa_latest.id')
                ->leftJoin('core.personas as person_dest', 'person_dest.id', '=', 'pa.persona_id')
                ->leftJoin('socios.socios as s', 's.persona_id', '=', 'person_dest.id');
        } else {
            $planQuery->leftJoin('core.personas as person_dest', 'person_dest.id', '=', 'p.persona_id');
        }

        $plan = $planQuery
            ->where('p.id', $planId)
            ->selectRaw("
                p.id,
                p.nombre,
                p.objetivo,
                p.estado,
                p.fecha_inicio,
                p.fecha_fin,
                " . ($hasAlcance ? "p.alcance" : "'GRUPAL' as alcance") . ",
                " . ($hasAssignments ? "pa.nombre_grupo" : "NULL as nombre_grupo") . ",
                person_dest.numero_identificacion as cedula,
                person_dest.nombres,
                person_dest.apellidos,
                " . ($hasAssignments ? "s.codigo_socio" : "NULL as codigo_socio") . "
            ")
            ->first();

        if (!$plan) {
            return ['plan' => null, 'rutinas' => []];
        }

        $ejercicios = DB::table('entrenamiento.plan_ejercicios as pe')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'pe.ejercicio_id')
            ->leftJoin('entrenamiento.plan_ejecuciones as ex', function ($join) use ($fecha) {
                $join->on('ex.plan_ejercicio_id', '=', 'pe.id')
                    ->where('ex.fecha_ejecucion', '=', $fecha);
            })
            ->where('pd.plan_id', $planId)
            ->selectRaw("
                pe.id,
                pd.plan_id,
                pd.semana,
                pd.dia,
                pb.nombre as bloque,
                pb.orden as bloque_orden,
                pe.ejercicio_id,
                pe.orden,
                pe.observaciones as notas,
                pe.tempo,
                pe.rpe_objetivo as rpe,
                pe.descanso_segundos,
                e.nombre as ejercicio_nombre,
                ex.id as ejecucion_id,
                ex.fecha_ejecucion,
                ex.estado as ejecucion_estado,
                ex.series_completadas,
                ex.repeticiones_reales,
                ex.carga_real,
                ex.unidad_carga_real,
                ex.rpe_real,
                ex.dolor_nivel,
                ex.observaciones as ejecucion_observaciones
            ")
            ->orderBy('pd.semana')
            ->orderByRaw("
                CASE pd.dia
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
            ->orderBy('pb.orden')
            ->orderBy('pe.orden')
            ->get();

        $ejercicioIds = $ejercicios->pluck('id')->map(fn ($id) => (int) $id)->all();

        $series = empty($ejercicioIds)
            ? collect()
            : DB::table('entrenamiento.plan_ejercicio_series')
                ->whereIn('plan_ejercicio_id', $ejercicioIds)
                ->orderBy('numero_serie')
                ->orderBy('id')
                ->get();

        $rutinas = $ejercicios->map(function ($item) use ($series) {
            $seriesEjercicio = $series->where('plan_ejercicio_id', $item->id)->values();
            $seriesCount = $seriesEjercicio->count();
            $repeticiones = $seriesEjercicio->pluck('repeticiones')->filter()->unique()->implode(", ");
            $firstFixedLoad = $seriesEjercicio->firstWhere('carga_fija', '!=', null);
            $firstPercent = $seriesEjercicio->firstWhere('porcentaje_rm', '!=', null);
            $firstUnit = $seriesEjercicio->firstWhere('unidad_carga', '!=', null);

            $cargaObjetivo = $firstFixedLoad?->carga_fija ?? $firstPercent?->porcentaje_rm;
            $tipoCarga = $firstFixedLoad ? 'FIJA' : ($firstPercent ? '%RM' : ($seriesEjercicio->first()?->tipo_carga ?? 'LIBRE'));
            $unidadObjetivo = $firstFixedLoad ? ($firstUnit?->unidad_carga ?? null) : ($firstPercent ? '%' : ($firstUnit?->unidad_carga ?? null));

            return [
                'id' => (int) $item->id,
                'plan_id' => (int) $item->plan_id,
                'source_type' => 'PLAN_EJERCICIO',
                'semana' => (int) $item->semana,
                'dia' => $item->dia,
                'bloque' => $item->bloque,
                'bloque_orden' => $item->bloque_orden !== null ? (int) $item->bloque_orden : 1,
                'ejercicio_id' => (int) $item->ejercicio_id,
                'ejercicio_nombre' => $item->ejercicio_nombre,
                'series' => $seriesCount,
                'repeticiones' => $repeticiones ?: null,
                'carga_objetivo' => $cargaObjetivo !== null ? (float) $cargaObjetivo : null,
                'tipo_carga' => $tipoCarga,
                'unidad_objetivo' => $unidadObjetivo,
                'tempo' => $item->tempo,
                'rpe' => $item->rpe !== null ? (float) $item->rpe : null,
                'descanso_segundos' => $item->descanso_segundos !== null ? (int) $item->descanso_segundos : null,
                'orden' => $item->orden !== null ? (int) $item->orden : 1,
                'notas' => $item->notas,
                'ejecucion_id' => $item->ejecucion_id ? (int) $item->ejecucion_id : null,
                'fecha_ejecucion' => $item->fecha_ejecucion,
                'ejecucion_estado' => $item->ejecucion_estado,
                'series_completadas' => $item->series_completadas !== null ? (int) $item->series_completadas : null,
                'repeticiones_reales' => $item->repeticiones_reales,
                'carga_real' => $item->carga_real !== null ? (float) $item->carga_real : null,
                'unidad_carga_real' => $item->unidad_carga_real,
                'rpe_real' => $item->rpe_real !== null ? (float) $item->rpe_real : null,
                'dolor_nivel' => $item->dolor_nivel !== null ? (int) $item->dolor_nivel : null,
                'ejecucion_observaciones' => $item->ejecucion_observaciones,
            ];
        })->all();

        $nombreCompleto = trim(($plan->nombres ?? '') . ' ' . ($plan->apellidos ?? ''));
        $destino = ($plan->alcance ?? 'GRUPAL') === 'INDIVIDUAL'
            ? ($nombreCompleto ?: 'Cliente asignado')
            : ($plan->nombre_grupo ?: 'Grupo asignado');

        return [
            'plan' => [
                'id' => (int) $plan->id,
                'nombre' => $plan->nombre,
                'objetivo' => $plan->objetivo,
                'estado' => $plan->estado,
                'alcance' => $plan->alcance ?? 'GRUPAL',
                'fecha_inicio' => $plan->fecha_inicio,
                'fecha_fin' => $plan->fecha_fin,
                'cedula' => $plan->cedula,
                'nombre_completo' => $nombreCompleto ?: null,
                'destino' => $destino,
                'codigo_socio' => $plan->codigo_socio,
            ],
            'rutinas' => $rutinas,
        ];
    }

    public function listarReporteSecuenciasPorPlan(int $planId): array
    {
        $rows = DB::table('entrenamiento.plan_ejercicios as pe')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'pe.ejercicio_id')
            ->leftJoin('entrenamiento.plan_ejecuciones as ex', 'ex.plan_ejercicio_id', '=', 'pe.id')
            ->where('pd.plan_id', $planId)
            ->selectRaw("
                pe.id as plan_ejercicio_id,
                pe.ejercicio_id,
                e.nombre as ejercicio_nombre,
                pd.semana,
                pd.dia,
                pb.nombre as bloque,
                ex.id as ejecucion_id,
                ex.fecha_ejecucion,
                ex.estado,
                ex.repeticiones_reales,
                ex.carga_real,
                ex.observaciones
            ")
            ->orderBy('pd.semana')
            ->orderByRaw("
                CASE pd.dia
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
            ->orderBy('pb.orden')
            ->orderBy('pe.orden')
            ->orderByDesc('ex.fecha_ejecucion')
            ->get();

        $planExerciseIds = $rows->pluck('plan_ejercicio_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $series = empty($planExerciseIds)
            ? collect()
            : DB::table('entrenamiento.plan_ejercicio_series')
                ->whereIn('plan_ejercicio_id', $planExerciseIds)
                ->orderBy('numero_serie')
                ->orderBy('id')
                ->get()
                ->groupBy('plan_ejercicio_id');

        $latestRows = [];
        foreach ($rows as $row) {
            $id = (int) $row->plan_ejercicio_id;
            if (!isset($latestRows[$id]) || (!$latestRows[$id]->ejecucion_id && $row->ejecucion_id)) {
                $latestRows[$id] = $row;
            }
        }

        $weeks = [];
        foreach ($latestRows as $row) {
            $week = (int) $row->semana;
            if (!isset($weeks[$week])) {
                $weeks[$week] = [
                    'label' => 'Sem ' . $week,
                    'week' => $week,
                    'plannedReps' => 0,
                    'actualReps' => 0,
                    'plannedVolume' => 0,
                    'actualVolume' => 0,
                    'exercises' => [],
                ];
            }

            $plannedSeries = [];
            $plannedReps = 0;
            $plannedVolume = 0;
            foreach (($series->get($row->plan_ejercicio_id) ?? collect()) as $serie) {
                $reps = (int) $this->parseNumber($serie->repeticiones ?? 0);
                $load = $this->parseNumber($serie->carga_fija ?? 0);
                $volume = $load > 0 && $reps > 0 ? $load * $reps : 0;
                $plannedReps += $reps;
                $plannedVolume += $volume;
                $plannedSeries[] = [
                    'number' => (int) ($serie->numero_serie ?? (count($plannedSeries) + 1)),
                    'reps' => $reps,
                    'load' => round($load, 1),
                    'volume' => round($volume, 1),
                ];
            }

            $actual = $this->extractSequenceMetrics($row->repeticiones_reales, $row->carga_real);

            $weeks[$week]['plannedReps'] += $plannedReps;
            $weeks[$week]['actualReps'] += $actual['reps'];
            $weeks[$week]['plannedVolume'] += $plannedVolume;
            $weeks[$week]['actualVolume'] += $actual['volume'];
            $weeks[$week]['exercises'][] = [
                'id' => (int) $row->plan_ejercicio_id,
                'exerciseId' => (int) $row->ejercicio_id,
                'name' => $row->ejercicio_nombre,
                'day' => $row->dia,
                'block' => $row->bloque,
                'state' => $row->estado,
                'date' => $row->fecha_ejecucion,
                'plannedReps' => $plannedReps,
                'actualReps' => $actual['reps'],
                'plannedVolume' => round($plannedVolume, 1),
                'actualVolume' => round($actual['volume'], 1),
                'compliance' => $plannedReps > 0 ? (int) round(($actual['reps'] / $plannedReps) * 100) : 0,
                'plannedSeries' => $plannedSeries,
                'actualSeries' => $actual['series'],
                'observation' => $row->observaciones,
            ];
        }

        ksort($weeks);
        $weekly = array_values(array_map(function ($week) {
            $week['plannedReps'] = (int) $week['plannedReps'];
            $week['actualReps'] = (int) $week['actualReps'];
            $week['plannedVolume'] = round($week['plannedVolume'], 1);
            $week['actualVolume'] = round($week['actualVolume'], 1);
            $week['compliance'] = $week['plannedReps'] > 0
                ? (int) round(($week['actualReps'] / $week['plannedReps']) * 100)
                : 0;

            return $week;
        }, $weeks));

        $plannedReps = array_sum(array_column($weekly, 'plannedReps'));
        $actualReps = array_sum(array_column($weekly, 'actualReps'));
        $plannedVolume = array_sum(array_column($weekly, 'plannedVolume'));
        $actualVolume = array_sum(array_column($weekly, 'actualVolume'));

        return [
            'plannedReps' => (int) $plannedReps,
            'actualReps' => (int) $actualReps,
            'plannedVolume' => round($plannedVolume, 1),
            'actualVolume' => round($actualVolume, 1),
            'compliance' => $plannedReps > 0 ? (int) round(($actualReps / $plannedReps) * 100) : 0,
            'weeks' => $weekly,
        ];
    }

    private function extractSequenceMetrics(?string $rawSeries, $fallbackLoad = null): array
    {
        $decoded = json_decode($rawSeries ?? '[]', true);
        $series = is_array($decoded) ? $decoded : [];

        if (empty($series) && $rawSeries) {
            $series = [[
                'set' => 1,
                'reps' => $this->parseNumber($rawSeries),
                'carga' => $this->parseNumber($fallbackLoad ?? 0),
            ]];
        }

        $reps = 0;
        $volume = 0;
        $normalized = [];

        foreach ($series as $index => $serie) {
            $load = $this->parseNumber($serie['carga'] ?? $serie['load'] ?? $fallbackLoad ?? 0);
            $serieReps = (int) $this->parseNumber($serie['reps'] ?? $serie['repeticiones'] ?? 0);
            $reps += $serieReps;
            $volume += $load * $serieReps;
            $normalized[] = [
                'number' => (int) ($serie['numero_serie'] ?? $serie['set'] ?? ($index + 1)),
                'reps' => $serieReps,
                'load' => round($load, 1),
            ];
        }

        return [
            'reps' => $reps,
            'volume' => $volume,
            'series' => $normalized,
        ];
    }

    private function parseNumber($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(',', '.', (string) $value);
        if (preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches)) {
            return (float) $matches[0];
        }

        return 0;
    }

    public function listarHistorialPorPlan(int $planId): array
    {
        return DB::table('entrenamiento.plan_ejecuciones as ex')
            ->join('entrenamiento.plan_ejercicios as pe', 'pe.id', '=', 'ex.plan_ejercicio_id')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
            ->where('ex.plan_id', $planId)
            ->selectRaw("
                ex.fecha_ejecucion,
                COUNT(ex.id) as total,
                SUM(CASE WHEN ex.estado = 'COMPLETADO' THEN 1 ELSE 0 END) as completados,
                SUM(CASE WHEN ex.estado IN ('PARCIAL', 'COMPLETADO_CON_AJUSTE') THEN 1 ELSE 0 END) as parciales,
                SUM(CASE WHEN ex.estado = 'OMITIDO' THEN 1 ELSE 0 END) as omitidos,
                AVG(ex.rpe_real) as rpe_promedio,
                AVG(ex.dolor_nivel) as dolor_promedio,
                MIN(pd.semana) as semana_min,
                MAX(pd.semana) as semana_max
            ")
            ->groupBy('ex.fecha_ejecucion')
            ->orderByDesc('ex.fecha_ejecucion')
            ->get()
            ->map(function ($item) {
                $total = (int) $item->total;
                $completados = (int) ($item->completados ?? 0);
                $parciales = (int) ($item->parciales ?? 0);
                $omitidos = (int) ($item->omitidos ?? 0);
                $cumplimiento = $total > 0 ? (int) round((($completados + ($parciales * 0.5)) / $total) * 100) : 0;

                return [
                    'fecha_ejecucion' => $item->fecha_ejecucion,
                    'total' => $total,
                    'completados' => $completados,
                    'parciales' => $parciales,
                    'omitidos' => $omitidos,
                    'pendientes' => max($total - $completados - $parciales - $omitidos, 0),
                    'cumplimiento' => $cumplimiento,
                    'rpe_promedio' => $item->rpe_promedio !== null ? round((float) $item->rpe_promedio, 1) : null,
                    'dolor_promedio' => $item->dolor_promedio !== null ? round((float) $item->dolor_promedio, 1) : null,
                    'semana_min' => $item->semana_min !== null ? (int) $item->semana_min : null,
                    'semana_max' => $item->semana_max !== null ? (int) $item->semana_max : null,
                ];
            })
            ->all();
    }

    public function listarProgresoPorPlan(int $planId): array
    {
        return DB::table('entrenamiento.plan_ejecuciones as ex')
            ->join('entrenamiento.plan_ejercicios as pe', 'pe.id', '=', 'ex.plan_ejercicio_id')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
            ->leftJoin('entrenamiento.ejercicios as e', 'e.id', '=', 'pe.ejercicio_id')
            ->where('ex.plan_id', $planId)
            ->whereNotNull('pe.ejercicio_id')
            ->whereIn('ex.estado', ['COMPLETADO', 'COMPLETADO_CON_AJUSTE', 'PARCIAL'])
            ->select(
                'pe.ejercicio_id',
                'ex.fecha_ejecucion',
                'ex.carga_real',
                'ex.rpe_real',
                'ex.dolor_nivel',
                'ex.repeticiones_reales',
                'ex.estado',
                'pd.semana',
                'pd.dia'
            )
            ->orderBy('ex.fecha_ejecucion', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'ejercicio_id' => (int) $item->ejercicio_id,
                    'fecha_ejecucion' => $item->fecha_ejecucion,
                    'carga_real' => $item->carga_real !== null ? (float) $item->carga_real : null,
                    'rpe_real' => $item->rpe_real !== null ? (float) $item->rpe_real : null,
                    'dolor_nivel' => $item->dolor_nivel !== null ? (int) $item->dolor_nivel : null,
                    'repeticiones_reales' => $item->repeticiones_reales,
                    'estado' => $item->estado,
                    'semana' => (int) $item->semana,
                    'dia' => $item->dia,
                ];
            })
            ->all();
    }
}
