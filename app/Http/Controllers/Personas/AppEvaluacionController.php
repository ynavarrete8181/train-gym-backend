<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppEvaluacionController extends Controller
{
    public function getEvaluaciones(Request $request)
    {
        $personaId = null;
        if ($request->user()) {
            $personaId = $request->user()->persona_id;
        } else {
            // Mock para entorno local si no hay token
            $persona = DB::table('core.personas')->where('nombres', 'like', '%Yandry%')->first();
            $personaId = $persona ? $persona->id : null;
        }

        if (!$personaId) {
            return response()->json(['message' => 'Persona no encontrada'], 404);
        }

        $evaluaciones = DB::table('entrenamiento.evaluaciones')
            ->where('persona_id', $personaId)
            ->orderBy('fecha_evaluacion', 'desc')
            ->get();

        return response()->json([
            'message' => 'Evaluaciones físicas obtenidas exitosamente',
            'data' => $evaluaciones
        ]);
    }
}
