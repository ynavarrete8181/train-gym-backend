<?php

namespace App\Http\Controllers\Ejercicios;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Queries\Ejercicios\EjercicioQuery;
use Illuminate\Support\Facades\DB;

class AppEjercicioController extends Controller
{
    private EjercicioQuery $ejercicioQuery;

    public function __construct(EjercicioQuery $ejercicioQuery)
    {
        $this->ejercicioQuery = $ejercicioQuery;
    }

    /**
     * Obtiene los detalles de un ejercicio específico optimizados para la app móvil.
     * Incluye url_recurso, instrucciones, series, repeticiones, etc.
     */
    public function getExerciseDetail(Request $request, $id)
    {
        $ejercicio = $this->ejercicioQuery->obtenerPorId((int)$id);
        $planId = $request->query('plan_id');
        $planEjercicioId = $request->query('plan_ejercicio_id');

        if (!$ejercicio) {
            return response()->json(['message' => 'Ejercicio no encontrado'], 404);
        }

        // Obtener la persona actual para el historial
        $personaId = null;
        if ($request->user()) {
            $personaId = $request->user()->persona_id;
        } else {
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
        }

        $historial = [];
        $ejecucionHoy = null;
        if ($personaId) {
            $historialQuery = DB::table('entrenamiento.plan_ejecuciones as pe')
                ->join('entrenamiento.plan_ejercicios as p_ej', 'pe.plan_ejercicio_id', '=', 'p_ej.id')
                ->join('entrenamiento.planes as p', 'pe.plan_id', '=', 'p.id')
                ->where('p_ej.ejercicio_id', $id)
                ->whereIn('pe.estado', ['COMPLETADO', 'PARCIAL']);

            if ($planEjercicioId) {
                $historialQuery->where('p_ej.id', (int) $planEjercicioId);
            } else {
                $historialQuery->where('p.persona_id', $personaId);
            }

            $historialDb = $historialQuery
                ->orderBy('pe.fecha_ejecucion', 'desc')
                ->take(10)
                ->get();

            foreach ($historialDb as $h) {
                $historial[] = [
                    'fecha' => $h->fecha_ejecucion,
                    'series' => $h->series_completadas,
                    'carga' => $h->carga_real,
                    'rpe' => $h->rpe_real,
                    'dolor' => property_exists($h, 'dolor_nivel') ? $h->dolor_nivel : 0,
                    'detalle_series' => json_decode($h->repeticiones_reales, true)
                ];
            }

            if ($planId && $planEjercicioId) {
                $ejecucionHoyRow = DB::table('entrenamiento.plan_ejecuciones')
                    ->where('plan_id', (int) $planId)
                    ->where('plan_ejercicio_id', (int) $planEjercicioId)
                    ->whereDate('fecha_ejecucion', now()->toDateString())
                    ->first();

                if ($ejecucionHoyRow) {
                    $ejecucionHoy = [
                        'series' => json_decode($ejecucionHoyRow->repeticiones_reales, true) ?? [],
                        'rpe' => $ejecucionHoyRow->rpe_real,
                        'dolor_nivel' => $ejecucionHoyRow->dolor_nivel ?? null,
                        'obs' => $ejecucionHoyRow->observaciones,
                        'estado' => $ejecucionHoyRow->estado,
                    ];
                }
            }
        }

        // Construir el JSON consolidado que la app móvil espera.
        return response()->json([
            'message' => 'Ejercicio obtenido exitosamente',
            'data' => [
                'id' => (string) $ejercicio['id'],
                'name' => $ejercicio['nombre'],
                'videoUrl' => $ejercicio['url_recurso'],
                'tags' => array_values(array_filter([$ejercicio['grupo_muscular'], $ejercicio['equipamiento']])),
                'instructions' => $ejercicio['instrucciones'] ? [$ejercicio['instrucciones']] : [],
                // Campos adicionales según requiera la UI futura
                'plan_id' => $planId ? (int) $planId : null,
                'plan_ejercicio_id' => $planEjercicioId ? (int) $planEjercicioId : null,
                'note' => '',
                'series' => 0,
                'reps' => 0,
                'load' => 'Libre',
                'rpe' => '',
                'status' => $ejecucionHoy['estado'] ?? 'PENDIENTE',
                'ejecucion' => $ejecucionHoy,
                'historial' => $historial
            ]
        ]);
    }
}
