<?php

namespace App\Queries\Personas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReporteEvolucionQuery
{
    public function resumen(array $filtros = []): array
    {
        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        $personaId = !empty($filtros['persona_id']) ? (int) $filtros['persona_id'] : null;

        $personasQuery = DB::table('core.personas as p')
            ->selectRaw("
                p.id,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos
            ");

        if ($personaId) {
            $personasQuery->where('p.id', $personaId);
        }

        if ($buscar !== '') {
            $like = '%' . $buscar . '%';
            $personasQuery->where(function ($q) use ($like) {
                $q->where('p.numero_identificacion', 'like', $like)
                    ->orWhere('p.nombres', 'like', $like)
                    ->orWhere('p.apellidos', 'like', $like);
            });
        }

        $personas = $personasQuery
            ->orderBy('p.nombres')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'cedula' => $item->cedula,
                'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
            ])
            ->all();

        $personaIds = array_map(fn ($item) => $item['id'], $personas);

        if (empty($personaIds)) {
            return [
                'resumen' => [
                    'clientes' => 0,
                    'sesiones' => 0,
                    'adherencia_promedio' => 0,
                    'rm_registros' => 0,
                    'evaluaciones' => 0,
                ],
                'clientes' => [],
                'ultimos_rm' => [],
                'ultimas_evaluaciones' => [],
            ];
        }

        $hasAssignments = Schema::hasTable('entrenamiento.plan_asignaciones');
        $hasAlcance = Schema::hasColumn('entrenamiento.planes', 'alcance');

        if ($hasAssignments) {
            $latestAssignments = DB::table('entrenamiento.plan_asignaciones as pa')
                ->selectRaw('MAX(pa.id) as id, pa.plan_id')
                ->whereNotNull('pa.persona_id')
                ->groupBy('pa.plan_id');

            $planesPersona = DB::table('entrenamiento.planes as p')
                ->joinSub($latestAssignments, 'pa_latest', function ($join) {
                    $join->on('pa_latest.plan_id', '=', 'p.id');
                })
                ->join('entrenamiento.plan_asignaciones as pa', 'pa.id', '=', 'pa_latest.id')
                ->whereIn('pa.persona_id', $personaIds)
                ->when($hasAlcance, fn ($query) => $query->where('p.alcance', 'INDIVIDUAL'))
                ->selectRaw('p.id, pa.persona_id')
                ->get();
        } else {
            $planesPersona = DB::table('entrenamiento.planes')
                ->whereIn('persona_id', $personaIds)
                ->select('id', 'persona_id')
                ->get();
        }

        $planIds = $planesPersona->pluck('id')->map(fn ($id) => (int) $id)->all();
        $personaByPlan = $planesPersona->pluck('persona_id', 'id')->map(fn ($id) => (int) $id)->all();

        $ejecuciones = empty($planIds)
            ? collect([])
            : DB::table('entrenamiento.plan_ejecuciones as ex')
                ->join('entrenamiento.plan_ejercicios as pe', 'pe.id', '=', 'ex.plan_ejercicio_id')
                ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
                ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
                ->whereIn('ex.plan_id', $planIds)
                ->selectRaw("
                    ex.plan_id,
                    ex.fecha_ejecucion,
                    ex.estado,
                    ex.rpe_real,
                    ex.dolor_nivel,
                    pd.semana
                ")
                ->get();

        $sesionesPorPersona = [];
        foreach ($ejecuciones as $row) {
            $persona = $personaByPlan[(int) $row->plan_id] ?? null;
            if (!$persona) continue;
            $key = $persona . '|' . $row->fecha_ejecucion;
            if (!isset($sesionesPorPersona[$key])) {
                $sesionesPorPersona[$key] = [
                    'persona_id' => $persona,
                    'fecha_ejecucion' => $row->fecha_ejecucion,
                    'total' => 0,
                    'completados' => 0,
                    'parciales' => 0,
                    'omitidos' => 0,
                    'rpe_sum' => 0,
                    'rpe_count' => 0,
                    'dolor_sum' => 0,
                    'dolor_count' => 0,
                    'semana_min' => $row->semana !== null ? (int) $row->semana : null,
                    'semana_max' => $row->semana !== null ? (int) $row->semana : null,
                ];
            }

            $session = &$sesionesPorPersona[$key];
            $session['total'] += 1;
            if ($row->estado === 'COMPLETADO') $session['completados'] += 1;
            if (in_array($row->estado, ['PARCIAL', 'COMPLETADO_CON_AJUSTE'], true)) $session['parciales'] += 1;
            if ($row->estado === 'OMITIDO') $session['omitidos'] += 1;
            if ($row->rpe_real !== null) {
                $session['rpe_sum'] += (float) $row->rpe_real;
                $session['rpe_count'] += 1;
            }
            if ($row->dolor_nivel !== null) {
                $session['dolor_sum'] += (float) $row->dolor_nivel;
                $session['dolor_count'] += 1;
            }
            if ($row->semana !== null) {
                $session['semana_min'] = $session['semana_min'] === null ? (int) $row->semana : min($session['semana_min'], (int) $row->semana);
                $session['semana_max'] = $session['semana_max'] === null ? (int) $row->semana : max($session['semana_max'], (int) $row->semana);
            }
            unset($session);
        }

        $rmQuery = DB::table('entrenamiento.rm_registros as r')
            ->join('core.personas as p', 'p.id', '=', 'r.persona_id')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'r.ejercicio_id')
            ->whereIn('r.persona_id', $personaIds)
            ->selectRaw("
                r.id,
                r.persona_id,
                r.rm_estimado,
                r.fecha_registro,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos,
                e.nombre as ejercicio_nombre
            ")
            ->orderByDesc('r.fecha_registro')
            ->limit(12);

        $evaluacionesQuery = DB::table('entrenamiento.evaluaciones as e')
            ->join('core.personas as p', 'p.id', '=', 'e.persona_id')
            ->whereIn('e.persona_id', $personaIds)
            ->selectRaw("
                e.id,
                e.persona_id,
                e.tipo_evaluacion,
                e.fecha_evaluacion,
                e.resultado_resumen,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos
            ")
            ->orderByDesc('e.fecha_evaluacion')
            ->limit(12);

        $rmRegistros = $rmQuery->get()->map(fn ($item) => [
            'id' => (int) $item->id,
            'persona_id' => (int) $item->persona_id,
            'rm_estimado' => (float) $item->rm_estimado,
            'fecha_registro' => $item->fecha_registro,
            'cedula' => $item->cedula,
            'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
            'ejercicio_nombre' => $item->ejercicio_nombre,
        ])->all();

        $evaluaciones = $evaluacionesQuery->get()->map(fn ($item) => [
            'id' => (int) $item->id,
            'persona_id' => (int) $item->persona_id,
            'tipo_evaluacion' => $item->tipo_evaluacion,
            'fecha_evaluacion' => $item->fecha_evaluacion,
            'resultado_resumen' => $item->resultado_resumen,
            'cedula' => $item->cedula,
            'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
        ])->all();

        $clientes = collect($personas)->map(function ($persona) use ($sesionesPorPersona, $rmRegistros, $evaluaciones) {
            $sessions = collect($sesionesPorPersona)
                ->filter(fn ($item) => (int) $item['persona_id'] === (int) $persona['id'])
                ->sortByDesc('fecha_ejecucion')
                ->values();

            $sessionCount = $sessions->count();
            $adherencia = $sessionCount
                ? (int) round($sessions->avg(fn ($item) => $item['total'] > 0 ? (($item['completados'] + ($item['parciales'] * 0.5)) / $item['total']) * 100 : 0))
                : 0;
            $dolorPromedio = $sessionCount
                ? round($sessions->avg(fn ($item) => $item['dolor_count'] > 0 ? $item['dolor_sum'] / $item['dolor_count'] : 0), 1)
                : 0;
            $rpePromedio = $sessionCount
                ? round($sessions->avg(fn ($item) => $item['rpe_count'] > 0 ? $item['rpe_sum'] / $item['rpe_count'] : 0), 1)
                : 0;

            $topRm = collect($rmRegistros)
                ->where('persona_id', (int) $persona['id'])
                ->sortByDesc('rm_estimado')
                ->first();

            $ultimaEvaluacion = collect($evaluaciones)
                ->where('persona_id', (int) $persona['id'])
                ->sortByDesc('fecha_evaluacion')
                ->first();

            return [
                'persona_id' => (int) $persona['id'],
                'cedula' => $persona['cedula'],
                'nombre_completo' => $persona['nombre_completo'],
                'sesiones' => $sessionCount,
                'adherencia_promedio' => $adherencia,
                'dolor_promedio' => (float) $dolorPromedio,
                'rpe_promedio' => (float) $rpePromedio,
                'ultima_sesion' => $sessions->first()['fecha_ejecucion'] ?? null,
                'mejor_rm' => $topRm ? [
                    'valor' => $topRm['rm_estimado'],
                    'ejercicio_nombre' => $topRm['ejercicio_nombre'],
                    'fecha_registro' => $topRm['fecha_registro'],
                ] : null,
                'ultima_evaluacion' => $ultimaEvaluacion ? [
                    'tipo_evaluacion' => $ultimaEvaluacion['tipo_evaluacion'],
                    'fecha_evaluacion' => $ultimaEvaluacion['fecha_evaluacion'],
                    'resultado_resumen' => $ultimaEvaluacion['resultado_resumen'],
                ] : null,
            ];
        })->sortBy([
            ['adherencia_promedio', 'desc'],
            ['sesiones', 'desc'],
        ])->values()->all();

        $adherenciaPromedioGlobal = count($clientes)
            ? (int) round(collect($clientes)->avg('adherencia_promedio'))
            : 0;

        return [
            'resumen' => [
                'clientes' => count($personas),
                'sesiones' => count($sesionesPorPersona),
                'adherencia_promedio' => $adherenciaPromedioGlobal,
                'rm_registros' => count($rmRegistros),
                'evaluaciones' => count($evaluaciones),
            ],
            'clientes' => $clientes,
            'ultimos_rm' => $rmRegistros,
            'ultimas_evaluaciones' => $evaluaciones,
        ];
    }
}
