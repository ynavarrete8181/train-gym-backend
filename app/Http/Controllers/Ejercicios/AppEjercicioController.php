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
        $week = $request->query->has('week') ? max(1, (int) $request->query('week')) : null;
        $day = $request->query('day') ? strtolower((string) $request->query('day')) : null;

        if (!$ejercicio) {
            return response()->json(['message' => 'Ejercicio no encontrado'], 404);
        }

        $identity = $this->resolveAppIdentity($request);
        $personaId = $identity['persona_id'];

        $historial = [];
        $ejecucionHoy = null;
        if ($personaId) {
            $historialQuery = DB::table('entrenamiento.plan_ejecuciones as pe')
                ->join('entrenamiento.plan_ejercicios as p_ej', 'pe.plan_ejercicio_id', '=', 'p_ej.id')
                ->join('entrenamiento.planes as p', 'pe.plan_id', '=', 'p.id')
                ->where('p_ej.ejercicio_id', $id)
                ->whereIn('pe.estado', ['COMPLETADO', 'PARCIAL']);

            $this->applyIdentityFilter($historialQuery, $identity, 'pe');

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
                    ->when($week, fn ($query) => $query->where('semana', $week))
                    ->when($day, fn ($query) => $query->where('dia', $day))
                    ->when(!$week || !$day, fn ($query) => $query->whereDate('fecha_ejecucion', now()->toDateString()));

                $this->applyIdentityFilter($ejecucionHoyRow, $identity);

                $ejecucionHoyRow = $ejecucionHoyRow->first();

                if ($ejecucionHoyRow) {
                    $ejecucionHoy = [
                        'series' => json_decode($ejecucionHoyRow->repeticiones_reales, true) ?? [],
                        'rpe' => $ejecucionHoyRow->rpe_real,
                        'dolor_nivel' => $ejecucionHoyRow->dolor_nivel ?? null,
                        'rm_estimado_temporal' => $ejecucionHoyRow->rm_estimado_temporal ?? null,
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
                'week' => $week,
                'day' => $day,
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

    private function applyIdentityFilter($query, array $identity, ?string $alias = null): void
    {
        $prefix = $alias ? "{$alias}." : '';

        if ($identity['persona_id']) {
            $query->where($prefix . 'persona_id', $identity['persona_id']);
            return;
        }

        if ($identity['cedula']) {
            $query->where($prefix . 'cedula', $identity['cedula']);
        }
    }
}
