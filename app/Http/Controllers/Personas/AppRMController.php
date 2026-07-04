<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppRMController extends Controller
{
    public function getRMs(Request $request)
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

        $records = DB::table('entrenamiento.rm_registros as rm')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'rm.ejercicio_id')
            ->where('rm.persona_id', $personaId)
            ->orderBy('rm.rm_estimado', 'desc')
            ->select(
                'e.id as ejercicio_id', 
                'e.nombre as ejercicio', 
                'e.url_recurso as recurso_url',
                'rm.rm_estimado', 
                'rm.fecha_registro',
                'rm.peso as carga_usada',
                'rm.repeticiones as repeticiones_usadas'
            )
            ->get();

        return response()->json([
            'message' => 'Historial de RMs obtenido exitosamente',
            'data' => $records
        ]);
    }
}
