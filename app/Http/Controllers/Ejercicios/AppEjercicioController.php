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
        if ($personaId) {
            // Buscar últimas 3 ejecuciones de ESTE ejercicio (usando join con plan_ejercicios si es necesario o directo)
            $historialDb = DB::table('entrenamiento.plan_ejecuciones as pe')
                ->join('entrenamiento.plan_ejercicios as p_ej', 'pe.plan_ejercicio_id', '=', 'p_ej.id')
                ->join('entrenamiento.planes as p', 'pe.plan_id', '=', 'p.id')
                ->where('p.persona_id', $personaId) // o si es grupal, podemos no filtrar por p.persona_id y confiar en el usuario autenticado (si pe tuviera persona_id).
                // Pero como plan_ejecuciones no tiene persona_id directo (solo el plan), lo buscaremos usando la fecha y estado
                ->where('p_ej.ejercicio_id', $id)
                ->whereIn('pe.estado', ['COMPLETADO', 'PARCIAL'])
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
                'note' => '',
                'series' => 0,
                'reps' => 0,
                'load' => 'Libre',
                'rpe' => '',
                'status' => 'PENDIENTE',
                'historial' => $historial
            ]
        ]);
    }
}
