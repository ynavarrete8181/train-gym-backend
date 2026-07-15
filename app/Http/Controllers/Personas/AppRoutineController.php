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

                // Buscar si ya tiene una ejecución para hoy
                $ejecucion = DB::table('entrenamiento.plan_ejecuciones')
                    ->where('plan_id', $planId)
                    ->where('plan_ejercicio_id', $ejercicio['id'])
                    ->where('semana', (int) $week)
                    ->where('dia', $day);

                $this->applyIdentityFilter($ejecucion, $identity);

                $ejecucion = $ejecucion
                    ->first();

                $plannedSeries = $this->mapPlannedSeries(
                    $ejercicio,
                    $identity['persona_id'],
                    $ejecucion->rm_estimado_temporal ?? null
                );
                $firstLoad = $plannedSeries[0]['target_load'] ?? 'Libre';
                
                $mappedExercises[] = [
                    'id' => (string) $ejercicio['ejercicio_id'],
                    'plan_id' => $planId,
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
                        'rm_estimado_temporal' => $ejecucion->rm_estimado_temporal ?? null,
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

    private function mapPlannedSeries(array $ejercicio, ?int $personaId = null, mixed $temporaryRm = null): array
    {
        $series = is_array($ejercicio['series'] ?? null) ? $ejercicio['series'] : [];
        $rmContext = $this->resolveRmContext($ejercicio, $personaId, $temporaryRm);

        return array_map(function ($serie) use ($rmContext) {
            $prescription = $this->buildLoadPrescription($serie, $rmContext);
            $targetLoad = $prescription['display'];

            return [
                'numero_serie' => (int) ($serie['numero_serie'] ?? 0),
                'reps' => (string) ($serie['repeticiones'] ?? ''),
                'target_load' => $targetLoad,
                'tipo_carga' => $this->normalizeLoadType($serie['tipo_carga'] ?? null, $serie),
                'porcentaje_rm' => $serie['porcentaje_rm'] ?? null,
                'carga_fija' => $serie['carga_fija'] ?? null,
                'unidad_carga' => $serie['unidad_carga'] ?? 'kg',
                'tiempo_segundos' => $serie['tiempo_segundos'] ?? null,
                'distancia_metros' => $serie['distancia_metros'] ?? null,
                'rpe' => $serie['rpe'] ?? null,
                'descanso_segundos' => $serie['descanso_segundos'] ?? null,
                'prescripcion_carga' => $prescription,
            ];
        }, $series);
    }

    private function resolveTargetLoad(array $serie, mixed $rmValue): string
    {
        return $this->buildLoadPrescription($serie, [
            'rm' => $rmValue !== null ? (float) $rmValue : null,
            'source' => $rmValue !== null ? 'manual' : null,
            'registro_id' => null,
        ])['display'];
    }

    private function buildLoadPrescription(array $serie, array $rmContext): array
    {
        $type = $this->normalizeLoadType($serie['tipo_carga'] ?? null, $serie);
        $unit = $serie['unidad_carga'] ?? 'kg';
        $fixedLoad = $serie['carga_fija'] ?? null;
        $percentRm = $serie['porcentaje_rm'] ?? null;
        $rpe = $serie['rpe'] ?? null;
        $time = $serie['tiempo_segundos'] ?? null;
        $distance = $serie['distancia_metros'] ?? null;
        $rmValue = $rmContext['rm'] ?? null;

        $base = [
            'tipo' => $type,
            'display' => 'Libre',
            'unidad' => $unit,
            'rm_usado' => $rmValue,
            'rm_origen' => $rmContext['source'] ?? null,
            'rm_registro_id' => $rmContext['registro_id'] ?? null,
            'porcentaje_rm' => $percentRm !== null ? (float) $percentRm : null,
            'carga_calculada' => null,
            'carga_redondeada' => null,
            'barra_kg' => 20,
            'discos_por_lado' => [],
            'nota' => null,
        ];

        if ($type === 'RPE') {
            $base['display'] = $rpe !== null
                ? 'RPE ' . rtrim(rtrim(number_format((float) $rpe, 1, '.', ''), '0'), '.')
                : 'RPE';
            return $base;
        }

        if ($type === 'TIEMPO') {
            $base['display'] = $time !== null ? "{$time} seg" : 'Tiempo';
            return $base;
        }

        if ($type === 'DISTANCIA') {
            $base['display'] = $distance !== null ? "{$distance} m" : 'Distancia';
            return $base;
        }

        if ($fixedLoad !== null) {
            $load = (float) $fixedLoad;
            return [
                ...$base,
                'display' => $this->formatKg($load, $unit),
                'carga_calculada' => $load,
                ...$this->plateBreakdown($load),
            ];
        }

        if ($percentRm !== null) {
            $percent = (float) $percentRm;
            if ($rmValue !== null) {
                $calculated = ((float) $rmValue) * ($percent / 100);
                $breakdown = $this->plateBreakdown($calculated);
                return [
                    ...$base,
                    'display' => $this->formatKg($breakdown['carga_redondeada'], $unit),
                    'carga_calculada' => round($calculated, 2),
                    ...$breakdown,
                    'nota' => $breakdown['carga_redondeada'] !== round($calculated, 2)
                        ? 'Carga redondeada al disco disponible más cercano.'
                        : null,
                ];
            }

            $base['display'] = rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . "% RM";
            $base['nota'] = 'No hay RM registrado para calcular la carga.';
            return $base;
        }

        return $base;
    }

    private function resolveRmContext(array $ejercicio, ?int $personaId, mixed $temporaryRm = null): array
    {
        if ($temporaryRm !== null && (float) $temporaryRm > 0) {
            return [
                'rm' => round((float) $temporaryRm, 2),
                'source' => 'estimado_manual_sesion',
                'registro_id' => null,
            ];
        }

        $rmRegistroPersonaId = $ejercicio['rm_registro_persona_id'] ?? null;
        $rmRegistroPerteneceAlUsuario = $personaId
            && $rmRegistroPersonaId
            && (int) $rmRegistroPersonaId === (int) $personaId;

        if (($ejercicio['rm_registro_valor'] ?? null) !== null && $rmRegistroPerteneceAlUsuario) {
            return [
                'rm' => (float) $ejercicio['rm_registro_valor'],
                'source' => 'plan_rm_registro',
                'registro_id' => $ejercicio['rm_registro_id'] ?? null,
            ];
        }

        if (($ejercicio['rm_referencia'] ?? null) !== null) {
            return [
                'rm' => (float) $ejercicio['rm_referencia'],
                'source' => 'plan_rm_referencia',
                'registro_id' => null,
            ];
        }

        if (!$personaId || empty($ejercicio['ejercicio_id'])) {
            return ['rm' => null, 'source' => null, 'registro_id' => null];
        }

        $latestRm = DB::table('entrenamiento.rm_registros')
            ->where('persona_id', $personaId)
            ->where('ejercicio_id', (int) $ejercicio['ejercicio_id'])
            ->orderByDesc('fecha_registro')
            ->orderByDesc('id')
            ->select('id', 'rm_estimado')
            ->first();

        if ($latestRm && $latestRm->rm_estimado !== null) {
            return [
                'rm' => (float) $latestRm->rm_estimado,
                'source' => 'ultimo_rm_usuario',
                'registro_id' => (int) $latestRm->id,
            ];
        }

        $estimated = $this->estimateRmFromExecution($personaId, (int) $ejercicio['ejercicio_id']);

        return [
            'rm' => $estimated['rm'],
            'source' => $estimated['rm'] !== null ? 'estimado_por_historial' : null,
            'registro_id' => null,
        ];
    }

    private function estimateRmFromExecution(int $personaId, int $exerciseId): array
    {
        $rows = DB::table('entrenamiento.plan_ejecuciones as ex')
            ->join('entrenamiento.plan_ejercicios as pe', 'pe.id', '=', 'ex.plan_ejercicio_id')
            ->where('ex.persona_id', $personaId)
            ->where('pe.ejercicio_id', $exerciseId)
            ->whereIn('ex.estado', ['COMPLETADO', 'PARCIAL', 'COMPLETADO_CON_AJUSTE'])
            ->orderByDesc('ex.fecha_ejecucion')
            ->orderByDesc('ex.id')
            ->take(8)
            ->select('ex.repeticiones_reales', 'ex.carga_real', 'ex.rm_estimado_temporal')
            ->get();

        $best = null;

        foreach ($rows as $row) {
            if ($row->rm_estimado_temporal !== null && (float) $row->rm_estimado_temporal > 0) {
                $best = max($best ?? 0, (float) $row->rm_estimado_temporal);
                continue;
            }

            $series = json_decode($row->repeticiones_reales ?? '[]', true);
            if (!is_array($series) || empty($series)) {
                $series = [['carga' => $row->carga_real, 'reps' => 1]];
            }

            foreach ($series as $serie) {
                $load = (float) ($serie['carga'] ?? 0);
                $reps = (float) ($serie['reps'] ?? $serie['repeticiones'] ?? 0);
                if ($load <= 0 || $reps <= 0) {
                    continue;
                }
                $estimate = $reps <= 1 ? $load : $load * (1 + ($reps / 30));
                $best = max($best ?? 0, $estimate);
            }
        }

        return ['rm' => $best ? round($best, 1) : null];
    }

    private function normalizeLoadType(mixed $type, array $serie = []): string
    {
        $value = strtoupper(trim((string) ($type ?? '')));
        $value = str_replace([' ', '-'], '_', $value);

        if (in_array($value, ['%RM', 'RM', 'PORCENTAJE_RM', 'PORCENTAJE'], true)) {
            return 'PORCENTAJE_RM';
        }

        if (in_array($value, ['FIJA', 'PESO_FIJO', 'CARGA_FIJA'], true)) {
            return 'PESO_FIJO';
        }

        if ($value === '' && ($serie['porcentaje_rm'] ?? null) !== null) {
            return 'PORCENTAJE_RM';
        }

        if ($value === '' && ($serie['carga_fija'] ?? null) !== null) {
            return 'PESO_FIJO';
        }

        return $value ?: 'LIBRE';
    }

    private function plateBreakdown(float $targetLoad): array
    {
        $bar = 20.0;
        $rounded = round($targetLoad / 2.5) * 2.5;
        $sideLoad = max(0, ($rounded - $bar) / 2);
        $available = [25, 20, 15, 10, 5, 2.5, 1.25];
        $plates = [];
        $remaining = round($sideLoad, 2);

        foreach ($available as $plate) {
            $count = (int) floor(($remaining + 0.001) / $plate);
            if ($count > 0) {
                $plates[] = ['peso' => $plate, 'cantidad' => $count];
                $remaining = round($remaining - ($plate * $count), 2);
            }
        }

        return [
            'carga_redondeada' => round($rounded, 2),
            'barra_kg' => $bar,
            'discos_por_lado' => $plates,
            'restante_por_lado' => max(0, $remaining),
        ];
    }

    private function formatKg(float $value, string $unit = 'kg'): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . " {$unit}";
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
            'rm_estimado_manual' => 'nullable|numeric|min:0',
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
        $manualTemporaryRm = $request->filled('rm_estimado_manual') ? (float) $request->rm_estimado_manual : null;
        $temporaryRm = $manualTemporaryRm && $manualTemporaryRm > 0
            ? round($manualTemporaryRm, 2)
            : $this->calculateTemporaryRmFromSeries($series);

        // Usamos updateOrInsert
        DB::table('entrenamiento.plan_ejecuciones')->updateOrInsert(
            [
                'plan_id' => $request->plan_id,
                'plan_ejercicio_id' => $request->plan_ejercicio_id,
                'persona_id' => $identity['persona_id'],
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
                'rm_estimado_temporal' => $temporaryRm,
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

    public function calculateTemporaryRmLoads(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'plan_ejercicio_id' => ['required', 'integer'],
            'rm_estimado' => ['required', 'numeric', 'min:1'],
            'semana' => ['required', 'integer', 'min:1'],
            'dia' => ['required', 'string', 'max:20'],
            'fecha_ejecucion' => ['nullable', 'date'],
        ]);

        $identity = $this->resolveAppIdentity($request);
        if (!$identity['persona_id']) {
            return response()->json(['message' => 'No se pudo resolver la persona autenticada.'], 422);
        }

        $hasAssignment = DB::table('entrenamiento.plan_asignaciones')
            ->where('plan_id', (int) $data['plan_id'])
            ->where('persona_id', $identity['persona_id'])
            ->where('estado', 'ACTIVO')
            ->exists();

        if (!$hasAssignment) {
            return response()->json(['message' => 'El plan no está asignado a la persona autenticada.'], 403);
        }

        $planExercise = DB::table('entrenamiento.plan_ejercicios as pe')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
            ->where('pe.id', (int) $data['plan_ejercicio_id'])
            ->where('pd.plan_id', (int) $data['plan_id'])
            ->select('pe.id', 'pe.ejercicio_id')
            ->first();

        if (!$planExercise) {
            return response()->json(['message' => 'Ejercicio del plan no encontrado.'], 404);
        }

        $series = DB::table('entrenamiento.plan_ejercicio_series')
            ->where('plan_ejercicio_id', (int) $data['plan_ejercicio_id'])
            ->orderBy('numero_serie')
            ->orderBy('id')
            ->get()
            ->map(fn ($serie) => (array) $serie)
            ->all();

        $rmContext = [
            'rm' => round((float) $data['rm_estimado'], 2),
            'source' => 'estimado_manual_sesion',
            'registro_id' => null,
        ];

        DB::table('entrenamiento.plan_ejecuciones')->updateOrInsert(
            [
                'plan_id' => (int) $data['plan_id'],
                'plan_ejercicio_id' => (int) $data['plan_ejercicio_id'],
                'persona_id' => $identity['persona_id'],
                'semana' => (int) $data['semana'],
                'dia' => strtolower($data['dia']),
                'cedula' => $identity['cedula'],
            ],
            [
                'usuario_id' => $identity['usuario_id'],
                'estado' => 'PENDIENTE',
                'fecha_ejecucion' => $data['fecha_ejecucion'] ?? now()->toDateString(),
                'series_completadas' => 0,
                'rm_estimado_temporal' => $rmContext['rm'],
                'unidad_carga_real' => 'kg',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $plannedSeries = array_map(function ($serie) use ($rmContext) {
            $prescription = $this->buildLoadPrescription($serie, $rmContext);

            return [
                'numero_serie' => (int) ($serie['numero_serie'] ?? 0),
                'reps' => (string) ($serie['repeticiones'] ?? ''),
                'target_load' => $prescription['display'],
                'tipo_carga' => $this->normalizeLoadType($serie['tipo_carga'] ?? null, $serie),
                'porcentaje_rm' => $serie['porcentaje_rm'] ?? null,
                'carga_fija' => $serie['carga_fija'] ?? null,
                'unidad_carga' => $serie['unidad_carga'] ?? 'kg',
                'rpe' => $serie['rpe'] ?? null,
                'descanso_segundos' => $serie['descanso_segundos'] ?? null,
                'prescripcion_carga' => $prescription,
            ];
        }, $series);

        return response()->json([
            'status' => 'success',
            'rm_estimado_temporal' => $rmContext['rm'],
            'series' => $plannedSeries,
        ]);
    }

    public function clearTemporaryRm(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'plan_ejercicio_id' => ['required', 'integer'],
            'semana' => ['required', 'integer', 'min:1'],
            'dia' => ['required', 'string', 'max:20'],
        ]);

        $identity = $this->resolveAppIdentity($request);
        if (!$identity['persona_id']) {
            return response()->json(['message' => 'No se pudo resolver la persona autenticada.'], 422);
        }

        DB::table('entrenamiento.plan_ejecuciones')
            ->where('plan_id', (int) $data['plan_id'])
            ->where('plan_ejercicio_id', (int) $data['plan_ejercicio_id'])
            ->where('persona_id', $identity['persona_id'])
            ->where('semana', (int) $data['semana'])
            ->where('dia', strtolower($data['dia']))
            ->update([
                'rm_estimado_temporal' => null,
                'updated_at' => now(),
            ]);

        $plannedSeries = $this->getPlannedSeriesForPlanExercise(
            (int) $data['plan_id'],
            (int) $data['plan_ejercicio_id'],
            $identity['persona_id'],
            null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'RM temporal eliminado.',
            'series' => $plannedSeries,
        ]);
    }

    private function getPlannedSeriesForPlanExercise(
        int $planId,
        int $planExerciseId,
        ?int $personaId,
        mixed $temporaryRm = null
    ): array {
        $planExercise = DB::table('entrenamiento.plan_ejercicios as pe')
            ->join('entrenamiento.plan_bloques as pb', 'pb.id', '=', 'pe.plan_bloque_id')
            ->join('entrenamiento.plan_dias as pd', 'pd.id', '=', 'pb.plan_dia_id')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'pe.ejercicio_id')
            ->leftJoin('entrenamiento.rm_registros as rr', 'rr.id', '=', 'pe.rm_registro_id')
            ->where('pe.id', $planExerciseId)
            ->where('pd.plan_id', $planId)
            ->selectRaw("
                pe.*,
                e.nombre as ejercicio_nombre,
                rr.persona_id as rm_registro_persona_id,
                rr.rm_estimado as rm_registro_valor
            ")
            ->first();

        if (!$planExercise) {
            return [];
        }

        $series = DB::table('entrenamiento.plan_ejercicio_series')
            ->where('plan_ejercicio_id', $planExerciseId)
            ->orderBy('numero_serie')
            ->orderBy('id')
            ->get()
            ->map(fn ($serie) => (array) $serie)
            ->all();

        $exercise = (array) $planExercise;
        $exercise['series'] = $series;

        return $this->mapPlannedSeries($exercise, $personaId, $temporaryRm);
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

    private function calculateTemporaryRmFromSeries(array $series): ?float
    {
        $best = null;

        foreach ($series as $serie) {
            $load = (float) ($serie['carga'] ?? 0);
            $reps = (float) ($serie['reps'] ?? $serie['repeticiones'] ?? 0);

            if ($load <= 0 || $reps <= 0) {
                continue;
            }

            $estimate = $reps <= 1 ? $load : $load * (1 + ($reps / 30));
            $best = max($best ?? 0, $estimate);
        }

        return $best ? round($best, 2) : null;
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
