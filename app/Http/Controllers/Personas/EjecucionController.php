<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\EjecucionQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EjecucionController extends Controller
{
    public function __construct(private EjecucionQuery $query)
    {
    }

    public function planes()
    {
        return response()->json($this->query->listarPlanesDisponibles());
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
        ]);

        return response()->json($this->query->listarDetallePorPlanYFecha((int) $data['plan_id'], $data['fecha']));
    }

    public function history(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        return response()->json($this->query->listarHistorialPorPlan((int) $data['plan_id']));
    }

    public function progreso(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        return response()->json($this->query->listarProgresoPorPlan((int) $data['plan_id']));
    }

    public function reporteSecuencias(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        return response()->json($this->query->listarReporteSecuenciasPorPlan((int) $data['plan_id']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'plan_ejercicio_id' => ['required', 'integer'],
            'fecha_ejecucion' => ['required', 'date'],
            'estado' => ['required', 'string', 'max:20'],
            'series_completadas' => ['nullable', 'integer', 'min:0'],
            'repeticiones_reales' => ['nullable', 'string', 'max:2000'],
            'carga_real' => ['nullable', 'numeric', 'min:0'],
            'unidad_carga_real' => ['nullable', 'string', 'max:20'],
            'rpe_real' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'dolor_nivel' => ['nullable', 'integer', 'min:0', 'max:10'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $existing = DB::table('entrenamiento.plan_ejecuciones')
            ->where('plan_ejercicio_id', $data['plan_ejercicio_id'])
            ->where('fecha_ejecucion', $data['fecha_ejecucion'])
            ->first();

        if ($existing) {
            DB::table('entrenamiento.plan_ejecuciones')
                ->where('id', $existing->id)
                ->update([
                    'plan_id' => $data['plan_id'],
                    'estado' => $data['estado'],
                    'series_completadas' => $data['series_completadas'] ?? null,
                    'repeticiones_reales' => $data['repeticiones_reales'] ?? null,
                    'carga_real' => $data['carga_real'] ?? null,
                    'unidad_carga_real' => $data['unidad_carga_real'] ?? null,
                    'rpe_real' => $data['rpe_real'] ?? null,
                    'dolor_nivel' => $data['dolor_nivel'] ?? null,
                    'observaciones' => $data['observaciones'] ?? null,
                    'updated_at' => now(),
                ]);

            return response()->json(['message' => 'Ejecución actualizada correctamente.', 'id' => (int) $existing->id]);
        }

        $id = DB::table('entrenamiento.plan_ejecuciones')->insertGetId([
            'plan_id' => $data['plan_id'],
            'plan_ejercicio_id' => $data['plan_ejercicio_id'],
            'fecha_ejecucion' => $data['fecha_ejecucion'],
            'estado' => $data['estado'],
            'series_completadas' => $data['series_completadas'] ?? null,
            'repeticiones_reales' => $data['repeticiones_reales'] ?? null,
            'carga_real' => $data['carga_real'] ?? null,
            'unidad_carga_real' => $data['unidad_carga_real'] ?? null,
            'rpe_real' => $data['rpe_real'] ?? null,
            'dolor_nivel' => $data['dolor_nivel'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Ejecución registrada correctamente.', 'id' => $id], 201);
    }
}
