<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\EvaluacionRmQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluacionRmController extends Controller
{
    public function __construct(private EvaluacionRmQuery $query)
    {
    }

    public function indexEvaluaciones(Request $request)
    {
        return response()->json($this->query->listarEvaluaciones($request->only(['buscar', 'tipo_evaluacion'])));
    }

    public function storeEvaluacion(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'tipo_evaluacion' => ['required', 'string', 'max:50'],
            'fecha_evaluacion' => ['required', 'date'],
            'resultado_resumen' => ['nullable', 'string'],
            'nivel_resultado' => ['nullable', 'string', 'max:30'],
            'fecha_proxima_evaluacion' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $id = DB::table('entrenamiento.evaluaciones')->insertGetId([
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Evaluación registrada correctamente.', 'id' => $id], 201);
    }

    public function updateEvaluacion(Request $request, $id)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'tipo_evaluacion' => ['required', 'string', 'max:50'],
            'fecha_evaluacion' => ['required', 'date'],
            'resultado_resumen' => ['nullable', 'string'],
            'nivel_resultado' => ['nullable', 'string', 'max:30'],
            'fecha_proxima_evaluacion' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $updated = DB::table('entrenamiento.evaluaciones')->where('id', $id)->update([
            ...$data,
            'updated_at' => now(),
        ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró la evaluación a actualizar.'], 404);
        }

        return response()->json(['message' => 'Evaluación actualizada correctamente.']);
    }

    public function destroyEvaluacion($id)
    {
        $deleted = DB::table('entrenamiento.evaluaciones')->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró la evaluación a eliminar.'], 404);
        }

        return response()->json(['message' => 'Evaluación eliminada correctamente.']);
    }

    public function indexRm(Request $request)
    {
        return response()->json($this->query->listarRm($request->only(['buscar'])));
    }

    public function storeRm(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'ejercicio_id' => ['required', 'integer'],
            'tipo_registro' => ['required', 'string', 'max:20'],
            'peso' => ['required', 'numeric', 'min:0'],
            'repeticiones' => ['nullable', 'integer', 'min:1'],
            'rm_estimado' => ['required', 'numeric', 'min:0'],
            'fecha_registro' => ['required', 'date'],
            'fecha_proximo_control' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $id = DB::table('entrenamiento.rm_registros')->insertGetId([
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Registro RM guardado correctamente.', 'id' => $id], 201);
    }

    public function updateRm(Request $request, $id)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'ejercicio_id' => ['required', 'integer'],
            'tipo_registro' => ['required', 'string', 'max:20'],
            'peso' => ['required', 'numeric', 'min:0'],
            'repeticiones' => ['nullable', 'integer', 'min:1'],
            'rm_estimado' => ['required', 'numeric', 'min:0'],
            'fecha_registro' => ['required', 'date'],
            'fecha_proximo_control' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $updated = DB::table('entrenamiento.rm_registros')->where('id', $id)->update([
            ...$data,
            'updated_at' => now(),
        ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró el registro RM a actualizar.'], 404);
        }

        return response()->json(['message' => 'Registro RM actualizado correctamente.']);
    }

    public function destroyRm($id)
    {
        $deleted = DB::table('entrenamiento.rm_registros')->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró el registro RM a eliminar.'], 404);
        }

        return response()->json(['message' => 'Registro RM eliminado correctamente.']);
    }
}
