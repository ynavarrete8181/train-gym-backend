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
        $identity = $this->resolveAppIdentity($request);

        // 1. Obtener el último plan activo priorizando las asignaciones explícitas.
        $planId = null;

        if ($identity['persona_id']) {
            // Buscar si tiene asignación directa en plan_asignaciones (ej. planes grupales asignados)
            $asignacion = DB::table('entrenamiento.plan_asignaciones')
                ->where('persona_id', $identity['persona_id'])
                ->where('estado', 'ACTIVO')
                ->orderBy('id', 'desc')
                ->first();

            if ($asignacion) {
                // Verificar que el plan asignado también esté activo
                $planId = DB::table('entrenamiento.planes')
                    ->where('id', $asignacion->plan_id)
                    ->where('estado', 'ACTIVO')
                    ->value('id');
            }
            
            // Si no encontró o el asignado no está activo, buscar en tabla planes directamente
            // FALLBACK DESACTIVADO: La app debe respetar estrictamente las asignaciones.
            // Si la asignación está pausada, no debe mostrar el plan aunque el usuario sea el creador.
            /*
            if (!$planId) {
                $planId = DB::table('entrenamiento.planes')
                    ->where('persona_id', $identity['persona_id'])
                    ->where('estado', 'ACTIVO')
                    ->orderBy('id', 'desc')
                    ->value('id');
            }
            */
        }

        // Ya no se usa plan de respaldo global. Si no hay asignación, se devuelve error.
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
                    ->where('semana', (int) $week)
                    ->where('dia', $day)
                    ->when($identity['cedula'], fn ($query) => $query->where('cedula', $identity['cedula']))
                    ->when(!$identity['cedula'] && $identity['persona_id'], fn ($query) => $query->where('persona_id', $identity['persona_id']))
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
                'tiempo_segundos' => $serie['tiempo_segundos'] ?? null,
                'distancia_metros' => $serie['distancia_metros'] ?? null,
                'rpe' => $serie['rpe'] ?? null,
                'descanso_segundos' => $serie['descanso_segundos'] ?? null,
            ];
        }, $series);
    }

    private function resolveTargetLoad(array $serie, mixed $rmValue): string
    {
        $type = strtoupper((string) ($serie['tipo_carga'] ?? 'LIBRE'));
        $unit = $serie['unidad_carga'] ?? 'kg';
        $fixedLoad = $serie['carga_fija'] ?? null;
        $percentRm = $serie['porcentaje_rm'] ?? null;
        $rpe = $serie['rpe'] ?? null;
        $time = $serie['tiempo_segundos'] ?? null;
        $distance = $serie['distancia_metros'] ?? null;

        if ($type === 'RPE') {
            return $rpe !== null
                ? 'RPE ' . rtrim(rtrim(number_format((float) $rpe, 1, '.', ''), '0'), '.')
                : 'RPE';
        }

        if ($type === 'TIEMPO') {
            return $time !== null ? "{$time} seg" : 'Tiempo';
        }

        if ($type === 'DISTANCIA') {
            return $distance !== null ? "{$distance} m" : 'Distancia';
        }

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
            'semana' => 'required|integer|min:1',
            'dia' => 'required|string|max:20',
            'estado' => 'required|string|max:20',
            'series' => 'nullable|array',
            'rpe_real' => 'nullable|numeric',
            'dolor_nivel' => 'nullable|numeric|min:0|max:10',
            'observaciones' => 'nullable|string'
        ]);

        $identity = $this->resolveAppIdentity($request);

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
                'semana' => (int) $request->semana,
                'dia' => strtolower($request->dia),
                'cedula' => $identity['cedula'],
            ],
            [
                'persona_id' => $identity['persona_id'],
                'usuario_id' => $identity['usuario_id'],
                'estado' => $request->estado,
                'fecha_ejecucion' => $request->fecha_ejecucion,
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

        if (!$personaId) {
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
