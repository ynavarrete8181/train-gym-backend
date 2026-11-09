<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Simulación de cargas con un RM estimado por el propio deportista.
 * No persiste nada: el RM temporal solo recalcula la prescripción para la
 * pantalla actual. El RM oficial se sigue registrando desde administración.
 */
class RmTemporalController extends Controller
{
    private const KG_A_LB = 2.2046226218;

    public function calcular(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'plan_id' => 'required|integer',
            'plan_ejercicio_id' => 'required|integer',
            'rm_estimado' => 'required|numeric|min:1|max:2000',
            'semana' => 'nullable|integer|min:1|max:52',
            'dia' => 'nullable|string|max:20',
            'fecha_ejecucion' => 'nullable|date',
        ]);

        $rutina = $this->rutinaPropia($request, (int) $datos['plan_id'], (int) $datos['plan_ejercicio_id']);
        $rm = round((float) $datos['rm_estimado'], 2);

        return response()->json([
            'message' => 'Cargas calculadas con el RM estimado.',
            'rm_estimado_temporal' => $rm,
            'series' => $this->series($rutina, $rm, 'rm_temporal_app'),
        ]);
    }

    public function limpiar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'plan_id' => 'required|integer',
            'plan_ejercicio_id' => 'required|integer',
            'semana' => 'nullable|integer|min:1|max:52',
            'dia' => 'nullable|string|max:20',
        ]);

        $rutina = $this->rutinaPropia($request, (int) $datos['plan_id'], (int) $datos['plan_ejercicio_id']);
        $rm = DB::table('entrenamiento.rm_registros')
            ->where('cliente_id', $rutina->cliente_id)
            ->where('ejercicio_id', $rutina->ejercicio_id)
            ->orderByDesc('id')
            ->value('rm_estimado');

        return response()->json([
            'message' => 'Se restableció el RM registrado.',
            'rm_estimado_temporal' => null,
            'series' => $this->series($rutina, $rm !== null ? (float) $rm : null, $rm !== null ? 'ultimo_rm_usuario' : null),
        ]);
    }

    private function rutinaPropia(Request $request, int $planId, int $rutinaId): object
    {
        $deportistaId = DB::table('clientes.deportistas')->where('usuario_id', $request->user()->id)->value('id');
        abort_unless($deportistaId, 403, 'La cuenta no tiene un deportista vinculado.');

        $rutina = DB::table('entrenamiento.rutinas as r')
            ->join('entrenamiento.planes as p', 'p.id', '=', 'r.plan_id')
            ->where('r.id', $rutinaId)
            ->where('r.plan_id', $planId)
            ->where('p.cliente_id', $deportistaId)
            ->first(['r.*', 'p.cliente_id']);
        abort_unless($rutina, 404, 'La prescripción no pertenece a tu plan.');

        return $rutina;
    }

    /**
     * Mismo formato que series_planificadas de GET /api/app/rutinas.
     */
    private function series(object $rutina, ?float $rm, ?string $origen): array
    {
        $filas = DB::table('entrenamiento.rutina_series')
            ->where('rutina_id', $rutina->id)
            ->orderBy('numero_serie')
            ->get();

        if ($filas->isEmpty()) {
            $filas = collect(range(1, max(1, (int) $rutina->series)))->map(fn (int $n): object => (object) [
                'numero_serie' => $n,
                'tipo_carga' => $rutina->tipo_carga,
                'porcentaje_rm' => $rutina->tipo_carga === 'PORCENTAJE_RM' ? $rutina->carga_objetivo : null,
                'repeticiones' => $rutina->repeticiones,
                'descanso_segundos' => $rutina->descanso_segundos,
                'rpe' => null,
                'carga_fija' => $rutina->tipo_carga !== 'PORCENTAJE_RM' && is_numeric($rutina->carga_objetivo) ? $rutina->carga_objetivo : null,
            ]);
        }

        return $filas->map(function (object $serie) use ($rm, $origen): array {
            $porcentaje = $serie->porcentaje_rm !== null ? (float) $serie->porcentaje_rm : null;
            $kg = $serie->tipo_carga === 'PORCENTAJE_RM' && $rm !== null && $porcentaje !== null
                ? round(($rm * $porcentaje) / 100, 2)
                : ($serie->carga_fija !== null ? (float) $serie->carga_fija : null);
            $lb = $kg !== null ? round($kg * self::KG_A_LB, 2) : null;

            return [
                'numero_serie' => (int) $serie->numero_serie,
                'tipo_carga' => $serie->tipo_carga,
                'porcentaje_rm' => $porcentaje,
                'reps' => $serie->repeticiones,
                'descanso_segundos' => $serie->descanso_segundos,
                'rpe' => $serie->rpe !== null ? (float) $serie->rpe : null,
                'carga_kg' => $kg,
                'carga_lb' => $lb,
                'prescripcion_carga' => [
                    'carga_redondeada' => $kg,
                    'carga_libras' => $lb,
                    'rm_usado' => $rm,
                    'rm_origen' => $rm !== null ? $origen : null,
                ],
            ];
        })->values()->all();
    }
}
