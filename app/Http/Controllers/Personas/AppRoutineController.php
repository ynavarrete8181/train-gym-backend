<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Queries\Entrenamiento\PlanEntrenamientoQuery;
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
        $week = $request->query('week', 1);
        $day = strtolower($request->query('day', 'lunes'));

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
                    'week' => (int)$week,
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
                    ->where('fecha_ejecucion', date('Y-m-d'))
                    ->first();
                
                $mappedExercises[] = [
                    'id' => (string) $ejercicio['ejercicio_id'],
                    'plan_ejercicio_id' => $ejercicio['id'],
                    'name' => $ejercicio['ejercicio_nombre'],
                    'note' => $ejercicio['observaciones'] ?? '',
                    'series' => $seriesCount,
                    'reps' => $firstRep,
                    'load' => 'Libre',
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
                'week' => (int)$week,
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
        $days = ["domingo", "lunes", "martes", "miercoles", "jueves", "viernes", "sabado"];
        $today = $days[date('w')];
        
        $request->merge([
            'day' => $today,
            'week' => 1 // Temporalmente forzado a semana 1
        ]);

        return $this->getRoutineByDay($request);
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
