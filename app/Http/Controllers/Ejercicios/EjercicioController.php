<?php

namespace App\Http\Controllers\Ejercicios;

use App\Http\Controllers\Controller;
use App\Queries\Ejercicios\EjercicioQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EjercicioController extends Controller
{
    public function __construct(private EjercicioQuery $ejercicioQuery)
    {
    }

    public function index(Request $request)
    {
        $filtros = $request->only(['buscar', 'grupo_muscular', 'equipamiento']);
        $ejercicios = $this->ejercicioQuery->listar($filtros);
        return response()->json($ejercicios);
    }

    public function show($id)
    {
        $ejercicio = $this->ejercicioQuery->obtenerPorId((int) $id);

        if (!$ejercicio) {
            return response()->json([
                'message' => 'No se encontró el ejercicio solicitado.',
            ], 404);
        }

        return response()->json($ejercicio);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'grupo_muscular' => ['required', 'string', 'max:50'],
            'equipamiento' => ['required', 'string', 'max:50'],
            'tipo_entrenamiento' => ['nullable', 'string', 'max:50'],
            'instrucciones' => ['nullable', 'string'],
            'url_recurso' => ['nullable', 'string'],
        ]);

        $id = DB::table('entrenamiento.ejercicios')->insertGetId([
            'nombre' => $data['nombre'],
            'grupo_muscular' => $data['grupo_muscular'],
            'equipamiento' => $data['equipamiento'],
            'tipo_entrenamiento' => $data['tipo_entrenamiento'] ?? 'GENERAL',
            'instrucciones' => $data['instrucciones'] ?? null,
            'url_recurso' => $data['url_recurso'] ?? null,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Ejercicio registrado con éxito.',
            'id' => $id,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'grupo_muscular' => ['required', 'string', 'max:50'],
            'equipamiento' => ['required', 'string', 'max:50'],
            'tipo_entrenamiento' => ['nullable', 'string', 'max:50'],
            'instrucciones' => ['nullable', 'string'],
            'url_recurso' => ['nullable', 'string'],
        ]);

        $updated = DB::table('entrenamiento.ejercicios')
            ->where('id', $id)
            ->update([
                'nombre' => $data['nombre'],
                'grupo_muscular' => $data['grupo_muscular'],
                'equipamiento' => $data['equipamiento'],
                'tipo_entrenamiento' => $data['tipo_entrenamiento'] ?? 'GENERAL',
                'instrucciones' => $data['instrucciones'] ?? null,
                'url_recurso' => $data['url_recurso'] ?? null,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'message' => 'No se pudo actualizar el ejercicio o no existe.',
            ], 404);
        }

        return response()->json([
            'message' => 'Ejercicio actualizado con éxito.',
        ]);
    }

    public function destroy($id)
    {
        $deleted = DB::table('entrenamiento.ejercicios')
            ->where('id', $id)
            ->update([
                'activo' => false,
                'updated_at' => now(),
            ]);

        if (!$deleted) {
            return response()->json([
                'message' => 'No se pudo eliminar el ejercicio o no existe.',
            ], 404);
        }

        return response()->json([
            'message' => 'Ejercicio eliminado con éxito.',
        ]);
    }
}
