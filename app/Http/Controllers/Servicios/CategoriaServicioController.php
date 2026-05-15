<?php

namespace App\Http\Controllers\Servicios;

use App\Http\Controllers\Controller;
use App\Services\Servicios\CategoriaServicioService;
use Illuminate\Http\Request;

class CategoriaServicioController extends Controller
{
    public function __construct(private CategoriaServicioService $categoriaService)
    {
    }

    public function index()
    {
        return response()->json($this->categoriaService->all());
    }

    public function show(int $id)
    {
        $categoria = $this->categoriaService->find($id);

        if (!$categoria) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        return response()->json($categoria);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['nullable', 'string', 'max:255'],
            'txt_nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'txt_descripcion' => ['nullable', 'string'],
            'estado_id' => ['nullable'],
            'select_id_estado' => ['nullable'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $nombre = trim((string) ($data['nombre'] ?? $data['txt_nombre'] ?? ''));
        if ($nombre === '') {
            return response()->json(['message' => 'El nombre es obligatorio'], 422);
        }

        $categoria = $this->categoriaService->create($data, $request);

        return response()->json([
            'message' => 'Categoría creada correctamente',
            'data' => $categoria,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'nombre' => ['nullable', 'string', 'max:255'],
            'txt_nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'txt_descripcion' => ['nullable', 'string'],
            'estado_id' => ['nullable'],
            'select_id_estado' => ['nullable'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $nombre = trim((string) ($data['nombre'] ?? $data['txt_nombre'] ?? ''));
        if ($nombre === '') {
            return response()->json(['message' => 'El nombre es obligatorio'], 422);
        }

        $categoria = $this->categoriaService->update($id, $data, $request);

        if (!$categoria) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        return response()->json([
            'message' => 'Categoría actualizada correctamente',
            'data' => $categoria,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $categoria = $this->categoriaService->delete($id, $request);

        if (!$categoria) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        return response()->json([
            'message' => 'Categoría desactivada correctamente',
            'data' => $categoria,
        ]);
    }

    public function serviciosByCategoria(int $idCategoria)
    {
        return response()->json($this->categoriaService->serviciosByCategoria($idCategoria));
    }
}
