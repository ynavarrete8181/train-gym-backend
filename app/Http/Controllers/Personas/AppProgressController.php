<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Queries\Entrenamiento\PlanEntrenamientoQuery;
use Illuminate\Support\Facades\DB;

class AppProgressController extends Controller
{
    private PlanEntrenamientoQuery $planQuery;

    public function __construct(PlanEntrenamientoQuery $planQuery)
    {
        $this->planQuery = $planQuery;
    }

    public function getProgress(Request $request)
    {
        $identity = $this->resolveAppIdentity($request);
        $filter = $request->query('filter', 'todo'); // todo, semana_1, semana_2, etc, mes

        $planId = null;
        if ($identity['persona_id']) {
            $asignacion = DB::table('entrenamiento.plan_asignaciones')
                ->where('persona_id', $identity['persona_id'])
                ->where('estado', 'ACTIVO')
                ->orderBy('id', 'desc')
                ->first();

            if ($asignacion) {
                $planId = DB::table('entrenamiento.planes')
                    ->where('id', $asignacion->plan_id)
                    ->where('estado', 'ACTIVO')
                    ->value('id');
            }
        }

        if (!$planId) {
            return response()->json([
                'adherence' => 0,
                'sessions' => 0,
                'weeklyData' => [],
                'monthlyEvolution' => [],
                'noPlan' => true
            ]);
        }

        $detalle = $this->planQuery->obtenerDetallePlan($planId);
        if (!$detalle || empty($detalle['dias'])) {
            return response()->json([
                'adherence' => 0,
                'sessions' => 0,
                'weeklyData' => [],
                'monthlyEvolution' => [],
                'notConfigured' => true
            ]);
        }

        $totalExercises = 0;
        $filteredDays = [];
        
        $totalWeeks = 1;
        foreach ($detalle['dias'] as $d) {
            if ($d['semana'] > $totalWeeks) $totalWeeks = $d['semana'];
        }

        foreach ($detalle['dias'] as $dia) {
            $include = false;
            if ($filter === 'todo' || $filter === 'mes') {
                $include = true;
            } elseif (str_starts_with($filter, 'semana_')) {
                $targetWeek = (int) str_replace('semana_', '', $filter);
                if ((int) $dia['semana'] === $targetWeek) {
                    $include = true;
                }
            }

            if ($include) {
                $filteredDays[] = $dia;
                foreach ($dia['bloques'] as $bloque) {
                    $totalExercises += count($bloque['ejercicios']);
                }
            }
        }

        // Obtener ejecuciones
        $queryEjecuciones = DB::table('entrenamiento.plan_ejecuciones')
            ->where('plan_id', $planId)
            ->whereIn('estado', ['COMPLETADO', 'PARCIAL', 'COMPLETADO_CON_AJUSTE']);

        $this->applyIdentityFilter($queryEjecuciones, $identity);

        if (str_starts_with($filter, 'semana_')) {
            $targetWeek = (int) str_replace('semana_', '', $filter);
            $queryEjecuciones->where('semana', $targetWeek);
        }

        $ejecuciones = $queryEjecuciones->get();

        $completedExercises = $ejecuciones->count();
        $adherence = $totalExercises > 0 ? round(($completedExercises / $totalExercises) * 100) : 0;

        // Calcular sesiones (dias distintos)
        $sesionesUnicas = [];
        foreach ($ejecuciones as $ej) {
            $key = $ej->semana . '_' . $ej->dia;
            $sesionesUnicas[$key] = true;
        }
        $sessionsCount = count($sesionesUnicas);

        // Weekly Data (Resumen L M X J V S D)
        // Para esto tomaremos los ejercicios completados por dia de la semana
        $weeklyReport = [
            'lunes' => ['total' => 0, 'completed' => 0, 'label' => 'L'],
            'martes' => ['total' => 0, 'completed' => 0, 'label' => 'M'],
            'miercoles' => ['total' => 0, 'completed' => 0, 'label' => 'X'],
            'jueves' => ['total' => 0, 'completed' => 0, 'label' => 'J'],
            'viernes' => ['total' => 0, 'completed' => 0, 'label' => 'V'],
            'sabado' => ['total' => 0, 'completed' => 0, 'label' => 'S'],
            'domingo' => ['total' => 0, 'completed' => 0, 'label' => 'D'],
        ];

        foreach ($filteredDays as $dia) {
            $d = strtolower($dia['dia']);
            if (isset($weeklyReport[$d])) {
                foreach ($dia['bloques'] as $bloque) {
                    $weeklyReport[$d]['total'] += count($bloque['ejercicios']);
                }
            }
        }

        foreach ($ejecuciones as $ej) {
            $d = strtolower($ej->dia);
            if (isset($weeklyReport[$d])) {
                $weeklyReport[$d]['completed']++;
            }
        }

        $weeklyData = [];
        foreach ($weeklyReport as $key => $data) {
            $pct = $data['total'] > 0 ? round(($data['completed'] / $data['total']) * 100) : 0;
            $weeklyData[] = [
                'value' => $pct,
                'label' => $data['label'],
                'frontColor' => $this->getColorForPercentage($pct),
            ];
        }

        // Monthly Evolution
        // Podemos mostrar el porcentaje de completitud por cada semana del plan
        $weeklyStats = [];
        for ($i = 1; $i <= $totalWeeks; $i++) {
            $weeklyStats[$i] = ['total' => 0, 'completed' => 0];
        }
        
        foreach ($detalle['dias'] as $dia) {
            $semana = $dia['semana'];
            foreach ($dia['bloques'] as $bloque) {
                $weeklyStats[$semana]['total'] += count($bloque['ejercicios']);
            }
        }

        $todasLasEjecuciones = DB::table('entrenamiento.plan_ejecuciones')
            ->where('plan_id', $planId)
            ->whereIn('estado', ['COMPLETADO', 'PARCIAL', 'COMPLETADO_CON_AJUSTE']);

        $this->applyIdentityFilter($todasLasEjecuciones, $identity);

        $todasLasEjecuciones = $todasLasEjecuciones->get();

        foreach ($todasLasEjecuciones as $ej) {
            $semana = $ej->semana;
            if (isset($weeklyStats[$semana])) {
                $weeklyStats[$semana]['completed']++;
            }
        }

        $monthlyEvolution = [];
        ksort($weeklyStats);
        foreach ($weeklyStats as $sem => $data) {
            $pct = $data['total'] > 0 ? round(($data['completed'] / $data['total']) * 100) : 0;
            $monthlyEvolution[] = [
                'value' => $pct,
                'label' => 'Sem ' . $sem
            ];
        }

        // Average RPE and Pain calculation for the current filter
        $totalRpe = 0;
        $totalPain = 0;
        $rpeCount = 0;
        $painCount = 0;
        
        foreach ($ejecuciones as $ej) {
            if (!is_null($ej->rpe_real)) {
                $totalRpe += $ej->rpe_real;
                $rpeCount++;
            }
            if (!is_null($ej->dolor_nivel)) {
                $totalPain += $ej->dolor_nivel;
                $painCount++;
            }
        }
        
        $averageRpe = $rpeCount > 0 ? round($totalRpe / $rpeCount, 1) : 0;
        $averagePain = $painCount > 0 ? round($totalPain / $painCount, 1) : 0;
        $performance = $this->summarizePerformance($ejecuciones, $filteredDays);

        // RPE Evolution across the month
        $weeklyRpeStats = [];
        for ($i = 1; $i <= $totalWeeks; $i++) {
            $weeklyRpeStats[$i] = ['totalRpe' => 0, 'count' => 0];
        }

        foreach ($todasLasEjecuciones as $ej) {
            $semana = $ej->semana;
            if (isset($weeklyRpeStats[$semana]) && !is_null($ej->rpe_real)) {
                $weeklyRpeStats[$semana]['totalRpe'] += $ej->rpe_real;
                $weeklyRpeStats[$semana]['count']++;
            }
        }

        $rpeEvolution = [];
        ksort($weeklyRpeStats);
        foreach ($weeklyRpeStats as $sem => $data) {
            $avg = $data['count'] > 0 ? round($data['totalRpe'] / $data['count'], 1) : 0;
            $rpeEvolution[] = [
                'value' => $avg,
                'label' => 'Sem ' . $sem
            ];
        }

        $nextGoal = "Sigue así, estás construyendo una excelente base.";
        if ($adherence >= 90) {
            $nextGoal = "¡Increíble consistencia! Mantén este ritmo ganador.";
        } elseif ($adherence < 60) {
            $nextGoal = "Intenta no saltarte las sesiones clave de esta semana.";
        }

        return response()->json([
            'adherence' => $adherence,
            'sessions' => $sessionsCount,
            'weeklyData' => $weeklyData,
            'monthlyEvolution' => $monthlyEvolution,
            'averageRpe' => $averageRpe,
            'averagePain' => $averagePain,
            'rpeEvolution' => $rpeEvolution,
            'performance' => $performance,
            'totalWeeks' => $totalWeeks,
            'nextGoal' => $nextGoal
        ]);
    }

    private function summarizePerformance($filteredExecutions, array $filteredDays): array
    {
        $plannedFiltered = $this->summarizePlannedPerformance($filteredDays);
        $summary = [
            'plannedReps' => $plannedFiltered['reps'],
            'totalVolume' => 0,
            'plannedVolume' => $plannedFiltered['volume'],
            'totalReps' => 0,
            'repsCompliance' => 0,
            'volumeCompliance' => 0,
            'maxLoad' => 0,
            'averageLoad' => 0,
            'hasLoadData' => false,
            'volumeEvolution' => [],
            'repsEvolution' => [],
            'comparisonEvolution' => [],
        ];

        $loadSum = 0;
        $loadCount = 0;
        foreach ($filteredExecutions as $execution) {
            $metrics = $this->extractExecutionMetrics($execution);
            $summary['totalVolume'] += $metrics['volume'];
            $summary['totalReps'] += $metrics['reps'];
            $summary['maxLoad'] = max($summary['maxLoad'], $metrics['maxLoad']);
            $loadSum += $metrics['loadSum'];
            $loadCount += $metrics['loadCount'];
        }

        $summary['totalVolume'] = round($summary['totalVolume'], 1);
        $summary['averageLoad'] = $loadCount > 0 ? round($loadSum / $loadCount, 1) : 0;
        $summary['hasLoadData'] = $loadCount > 0;
        $summary['repsCompliance'] = $summary['plannedReps'] > 0
            ? (int) round(($summary['totalReps'] / $summary['plannedReps']) * 100)
            : 0;
        $summary['volumeCompliance'] = $summary['plannedVolume'] > 0
            ? (int) round(($summary['totalVolume'] / $summary['plannedVolume']) * 100)
            : 0;

        $weeksToShow = collect($filteredDays)
            ->pluck('semana')
            ->map(fn ($week) => (int) $week)
            ->filter(fn ($week) => $week > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (empty($weeksToShow)) {
            $weeksToShow = [1];
        }

        $weeklyPerformance = [];
        foreach ($weeksToShow as $week) {
            $weeklyPerformance[$week] = [
                'plannedVolume' => 0,
                'plannedReps' => 0,
                'volume' => 0,
                'reps' => 0,
            ];
        }

        foreach ($filteredDays as $dia) {
            $week = (int) ($dia['semana'] ?? 0);
            if (!isset($weeklyPerformance[$week])) {
                continue;
            }

            $planned = $this->summarizePlannedPerformance([$dia]);
            $weeklyPerformance[$week]['plannedVolume'] += $planned['volume'];
            $weeklyPerformance[$week]['plannedReps'] += $planned['reps'];
        }

        foreach ($filteredExecutions as $execution) {
            $week = (int) $execution->semana;
            if (!isset($weeklyPerformance[$week])) {
                continue;
            }

            $metrics = $this->extractExecutionMetrics($execution);
            $weeklyPerformance[$week]['volume'] += $metrics['volume'];
            $weeklyPerformance[$week]['reps'] += $metrics['reps'];
        }

        $executionsByExercise = [];
        foreach ($filteredExecutions as $execution) {
            $key = ((int) $execution->semana) . '|' . ((int) $execution->plan_ejercicio_id);
            $executionsByExercise[$key] = $execution;
        }

        ksort($weeklyPerformance);
        foreach ($weeklyPerformance as $week => $data) {
            $weekDays = array_values(array_filter(
                $filteredDays,
                fn ($day) => (int) ($day['semana'] ?? 0) === (int) $week
            ));

            $summary['volumeEvolution'][] = [
                'value' => round($data['volume'], 1),
                'label' => 'Sem ' . $week,
            ];
            $summary['repsEvolution'][] = [
                'value' => (int) $data['reps'],
                'label' => 'Sem ' . $week,
                'frontColor' => '#F59E0B',
            ];
            $summary['comparisonEvolution'][] = [
                'label' => 'Sem ' . $week,
                'plannedReps' => (int) $data['plannedReps'],
                'actualReps' => (int) $data['reps'],
                'plannedVolume' => round($data['plannedVolume'], 1),
                'actualVolume' => round($data['volume'], 1),
                'exercises' => $this->summarizeExercisesForWeek($weekDays, $executionsByExercise),
            ];
        }

        return $summary;
    }

    private function summarizeExercisesForWeek(array $days, array $executionsByExercise): array
    {
        $items = [];

        foreach ($days as $day) {
            $week = (int) ($day['semana'] ?? 0);
            foreach (($day['bloques'] ?? []) as $block) {
                foreach (($block['ejercicios'] ?? []) as $exercise) {
                    $planExerciseId = (int) ($exercise['id'] ?? 0);
                    $plannedSeries = [];
                    $plannedReps = 0;
                    $plannedVolume = 0;

                    foreach (($exercise['series'] ?? []) as $serie) {
                        $reps = (int) $this->toFloat($serie['repeticiones'] ?? 0);
                        $load = $this->toFloat($serie['carga_fija'] ?? 0);
                        $volume = $load > 0 && $reps > 0 ? $load * $reps : 0;

                        $plannedReps += $reps;
                        $plannedVolume += $volume;
                        $plannedSeries[] = [
                            'number' => (int) ($serie['numero_serie'] ?? (count($plannedSeries) + 1)),
                            'reps' => $reps,
                            'load' => $load,
                            'volume' => round($volume, 1),
                        ];
                    }

                    $execution = $executionsByExercise[$week . '|' . $planExerciseId] ?? null;
                    $actual = $execution ? $this->extractExecutionMetrics($execution) : [
                        'volume' => 0,
                        'reps' => 0,
                        'maxLoad' => 0,
                        'loadSum' => 0,
                        'loadCount' => 0,
                        'series' => [],
                        'observation' => null,
                    ];

                    $items[] = [
                        'id' => $planExerciseId,
                        'name' => $exercise['ejercicio_nombre'] ?? 'Ejercicio',
                        'day' => $day['dia'] ?? null,
                        'plannedReps' => $plannedReps,
                        'actualReps' => (int) $actual['reps'],
                        'plannedVolume' => round($plannedVolume, 1),
                        'actualVolume' => round($actual['volume'], 1),
                        'compliance' => $plannedReps > 0 ? (int) round(($actual['reps'] / $plannedReps) * 100) : 0,
                        'plannedSeries' => $plannedSeries,
                        'actualSeries' => $actual['series'],
                        'observation' => $actual['observation'],
                    ];
                }
            }
        }

        return $items;
    }

    private function summarizePlannedPerformance(array $days): array
    {
        $summary = ['reps' => 0, 'volume' => 0];

        foreach ($days as $day) {
            foreach (($day['bloques'] ?? []) as $block) {
                foreach (($block['ejercicios'] ?? []) as $exercise) {
                    foreach (($exercise['series'] ?? []) as $serie) {
                        $reps = (int) $this->toFloat($serie['repeticiones'] ?? 0);
                        $load = $this->toFloat($serie['carga_fija'] ?? 0);

                        $summary['reps'] += $reps;
                        if ($load > 0 && $reps > 0) {
                            $summary['volume'] += $load * $reps;
                        }
                    }
                }
            }
        }

        $summary['volume'] = round($summary['volume'], 1);
        return $summary;
    }

    private function extractExecutionMetrics($execution): array
    {
        $series = json_decode($execution->repeticiones_reales ?? '[]', true);
        if (!is_array($series)) {
            $series = [];
        }

        $volume = 0;
        $reps = 0;
        $maxLoad = (float) ($execution->carga_real ?? 0);
        $loadSum = 0;
        $loadCount = 0;

        foreach ($series as $serie) {
            $load = $this->toFloat($serie['carga'] ?? 0);
            $serieReps = (int) $this->toFloat($serie['reps'] ?? 0);

            $volume += $load * $serieReps;
            $reps += $serieReps;
            $maxLoad = max($maxLoad, $load);

            if ($load > 0) {
                $loadSum += $load;
                $loadCount++;
            }
        }

        return [
            'volume' => $volume,
            'reps' => $reps,
            'maxLoad' => round($maxLoad, 1),
            'loadSum' => $loadSum,
            'loadCount' => $loadCount,
            'series' => array_values(array_map(fn ($serie) => [
                'number' => (int) ($serie['numero_serie'] ?? 0),
                'reps' => (int) $this->toFloat($serie['reps'] ?? 0),
                'load' => $this->toFloat($serie['carga'] ?? 0),
            ], $series)),
            'observation' => $execution->observaciones ?? null,
        ];
    }

    private function toFloat($value): float
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

    private function getColorForPercentage($pct)
    {
        if ($pct >= 90) return '#10B981'; // colors.success
        if ($pct >= 60) return '#F59E0B'; // colors.warning
        if ($pct > 0) return '#EF4444'; // colors.danger
        return '#E2E8F0'; // colors.surfaceAlt
    }

    private function applyIdentityFilter($query, array $identity): void
    {
        if ($identity['persona_id']) {
            $query->where('persona_id', $identity['persona_id']);
            return;
        }

        if ($identity['cedula']) {
            $query->where('cedula', $identity['cedula']);
        }
    }

    private function resolveAppIdentity(Request $request): array
    {
        $user = $request->user();
        $personaId = $user?->persona_id;
        $usuarioId = $user?->id;
        $cedula = null;

        if ($personaId) {
            $persona = DB::table('core.personas')
                ->where('id', $personaId)
                ->select('id', 'numero_identificacion')
                ->first();
            $cedula = $persona?->numero_identificacion;
        }

        if (!$cedula) {
            $cedula = $user?->cedula ?? null;
        }

        if (!$personaId && app()->environment(['local', 'testing'])) {
            // Mock para desarrollo local sin token real.
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
            $cedula = $persona?->numero_identificacion ?? $cedula;
            $usuarioId = DB::table('seguridad.usuarios')->where('persona_id', $personaId)->value('id') ?? $usuarioId;
        }

        return [
            'persona_id' => $personaId ? (int) $personaId : null,
            'usuario_id' => $usuarioId ? (int) $usuarioId : null,
            'cedula' => $cedula ? trim((string) $cedula) : null,
        ];
    }
}
