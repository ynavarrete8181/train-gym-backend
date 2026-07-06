<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Queries\Entrenamiento\PlanEntrenamientoQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AppRoutineController extends Controller
{
    private PlanEntrenamientoQuery $planQuery;

    public function __construct(PlanEntrenamientoQuery $planQuery)
    {
        $this->planQuery = $planQuery;
    }

    public function getRoutineByDay(Request $request)
    {
        $week = $request->query->has('week') ? max(1, (int) $request->query('week')) : null;
        $day = strtolower($request->query('day', $this->currentDayKey()));

        // Obtener el último plan activo
        $planId = DB::table('entrenamiento.planes')
            ->where('estado', 'ACTIVO')
            ->orderBy('id', 'desc')
            ->value('id');

        if (!$planId) {
            return response()->json(['message' => 'No hay planes activos'], 404);
        }

        // Consultar la nueva arquitectura
        $detalle = $this->planQuery->obtenerDetallePlan($planId);

        if (!$detalle) {
            return response()->json(['message' => 'Plan no encontrado'], 404);
        }

        // Si el plan no tiene días configurados
        if (empty($detalle['dias'])) {
            return response()->json([
                'message' => 'Tu plan aún no ha sido configurado por el entrenador.',
                'data' => null,
                'notConfigured' => true
            ]);
        }

        $totalWeeks = $this->getTotalWeeks($detalle['dias']);
        $week = $week ?: $this->resolveCurrentWeek($detalle['plan']['fecha_inicio'] ?? null, $totalWeeks);

        // Buscar el día exacto
        $diaBuscado = null;
        foreach ($detalle['dias'] as $d) {
            if (strtolower($d['dia']) === $day && $d['semana'] == $week) {
                $diaBuscado = $d;
                break;
            }
        }

        if (!$diaBuscado) {
            return response()->json([
                'message' => 'No hay rutina programada para este día.',
                'data' => [
                    'planName' => $detalle['plan']['nombre'],
                    'planStartDate' => $detalle['plan']['fecha_inicio'] ?? null,
                    'planEndDate' => $detalle['plan']['fecha_fin'] ?? null,
                    'week' => (int)$week,
                    'totalWeeks' => $totalWeeks,
                    'day' => $day,
                    'exercises' => []
                ]
            ]);
        }

        $mappedExercises = [];
        foreach ($diaBuscado['bloques'] as $bloque) {
            foreach ($bloque['ejercicios'] as $ejercicio) {
                $seriesCount = is_array($ejercicio['series']) ? count($ejercicio['series']) : 0;
                $firstRep = $seriesCount > 0 ? $ejercicio['series'][0]['repeticiones'] : 0;
                $plannedSeries = $this->mapPlannedSeries($ejercicio);
                $firstLoad = $plannedSeries[0]['target_load'] ?? 'Libre';
                
                // Buscar si ya tiene una ejecución para hoy
                $ejecucion = DB::table('entrenamiento.plan_ejecuciones')
                    ->where('plan_id', $planId)
                    ->where('plan_ejercicio_id', $ejercicio['id'])
                    ->where('fecha_ejecucion', date('Y-m-d'))
                    ->first();
                
                $mappedExercises[] = [
                    'id' => (string) $ejercicio['ejercicio_id'],
                    'plan_ejercicio_id' => $ejercicio['id'],
                    'name' => $ejercicio['ejercicio_nombre'],
                    'note' => $ejercicio['observaciones'] ?? '',
                    'series' => $seriesCount,
                    'reps' => $firstRep,
                    'load' => $firstLoad,
                    'plannedSeries' => $plannedSeries,
                    'rpe' => (string) ($ejercicio['rpe_objetivo'] ?? ''),
                    'status' => $ejecucion ? $ejecucion->estado : 'PENDIENTE',
                    'ejecucion' => $ejecucion ? [
                        'series' => json_decode($ejecucion->repeticiones_reales, true) ?? [],
                        'rpe' => $ejecucion->rpe_real,
                        'dolor_nivel' => $ejecucion->dolor_nivel ?? null,
                        'obs' => $ejecucion->observaciones
                    ] : null,
                ];
            }
        }

        return response()->json([
            'message' => 'Rutina obtenida exitosamente',
            'data' => [
                'planId' => $planId,
                'planName' => $detalle['plan']['nombre'],
                'planStartDate' => $detalle['plan']['fecha_inicio'] ?? null,
                'planEndDate' => $detalle['plan']['fecha_fin'] ?? null,
                'week' => (int)$week,
                'totalWeeks' => $totalWeeks,
                'day' => $day,
                'exercises' => $mappedExercises
            ]
        ]);
    }

    /**
     * Helper para obtener la rutina específicamente para hoy.
     */
    public function getTodayRoutine(Request $request)
    {
        $request->merge([
            'day' => $this->currentDayKey(),
        ]);

        return $this->getRoutineByDay($request);
    }

    private function currentDayKey(): string
    {
        $days = ["domingo", "lunes", "martes", "miercoles", "jueves", "viernes", "sabado"];
        return $days[(int) date('w')];
    }

    private function getTotalWeeks(array $days): int
    {
        $weeks = array_map(fn ($day) => (int) ($day['semana'] ?? 1), $days);
        return max(1, max($weeks ?: [1]));
    }

    private function resolveCurrentWeek(?string $planStartDate, int $totalWeeks): int
    {
        if (!$planStartDate) {
            return 1;
        }

        try {
            $start = Carbon::parse($planStartDate)->startOfDay();
            $today = Carbon::today();
            $daysElapsed = (int) max(0, $start->diffInDays($today, false));
            $currentWeek = intdiv($daysElapsed, 7) + 1;
            return min(max(1, $currentWeek), max(1, $totalWeeks));
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function mapPlannedSeries(array $ejercicio): array
    {
        $series = is_array($ejercicio['series'] ?? null) ? $ejercicio['series'] : [];
        $rmValue = $ejercicio['rm_registro_valor'] ?? $ejercicio['rm_referencia'] ?? null;

        return array_map(function ($serie) use ($rmValue) {
            $targetLoad = $this->resolveTargetLoad($serie, $rmValue);

            return [
                'numero_serie' => (int) ($serie['numero_serie'] ?? 0),
                'reps' => (string) ($serie['repeticiones'] ?? ''),
                'target_load' => $targetLoad,
                'tipo_carga' => $serie['tipo_carga'] ?? null,
                'porcentaje_rm' => $serie['porcentaje_rm'] ?? null,
                'carga_fija' => $serie['carga_fija'] ?? null,
                'unidad_carga' => $serie['unidad_carga'] ?? 'kg',
            ];
        }, $series);
    }

    private function resolveTargetLoad(array $serie, mixed $rmValue): string
    {
        $unit = $serie['unidad_carga'] ?? 'kg';
        $fixedLoad = $serie['carga_fija'] ?? null;
        $percentRm = $serie['porcentaje_rm'] ?? null;

        if ($fixedLoad !== null) {
            return rtrim(rtrim(number_format((float) $fixedLoad, 2, '.', ''), '0'), '.') . " {$unit}";
        }

        if ($percentRm !== null) {
            $percent = (float) $percentRm;
            if ($rmValue !== null) {
                $calculated = ((float) $rmValue) * ($percent / 100);
                return rtrim(rtrim(number_format($calculated, 1, '.', ''), '0'), '.') . " {$unit}";
            }

            return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . "% RM";
        }

        return 'Libre';
    }

    /**
     * Registra la ejecución de un ejercicio en la bitácora por series.
     */
    public function registerExecution(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|integer',
            'plan_ejercicio_id' => 'required|integer',
            'fecha_ejecucion' => 'required|date',
            'estado' => 'required|string|max:20',
            'series' => 'nullable|array',
            'rpe_real' => 'nullable|numeric',
            'dolor_nivel' => 'nullable|numeric|min:0|max:10',
            'observaciones' => 'nullable|string'
        ]);

        $personaId = null;
        if ($request->user()) {
            $personaId = $request->user()->persona_id;
        } else {
            // Mock para local
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
        }

        // Procesar series para sacar la carga máxima (opcional, para estadísticas rápidas)
        $series = $request->series ?? [];
        $maxCarga = 0;
        foreach ($series as $s) {
            $carga = floatval($s['carga'] ?? 0);
            if ($carga > $maxCarga) {
                $maxCarga = $carga;
            }
        }

        // Usamos updateOrInsert
        DB::table('entrenamiento.plan_ejecuciones')->updateOrInsert(
            [
                'plan_id' => $request->plan_id,
                'plan_ejercicio_id' => $request->plan_ejercicio_id,
                'fecha_ejecucion' => $request->fecha_ejecucion,
            ],
            [
                'estado' => $request->estado,
                'series_completadas' => count($series),
                'repeticiones_reales' => json_encode($series),
                'carga_real' => $maxCarga,
                'unidad_carga_real' => 'kg',
                'rpe_real' => $request->rpe_real,
                'dolor_nivel' => $request->dolor_nivel,
                'observaciones' => $request->observaciones,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Ejecución registrada con éxito',
            'status' => 'success'
        ]);
    }
}
