<?php

namespace App\Http\Controllers\Servicios;

use App\Http\Controllers\Controller;
use App\Services\Servicios\ServicioService;
use Illuminate\Http\Request;

class ServicioController extends Controller
{
    public function __construct(private ServicioService $servicioService)
    {
    }

    public function index()
    {
        return response()->json($this->servicioService->all());
    }

    public function show(int $id)
    {
        $servicio = $this->servicioService->find($id);

        if (!$servicio) {
            return response()->json(['message' => 'Servicio no encontrado'], 404);
        }

        return response()->json($servicio);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'categoria_id' => ['nullable', 'integer'],
            'select_id_categoria' => ['nullable', 'integer'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'txt_nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'txt_descripcion' => ['nullable', 'string'],
            'breve_desc' => ['nullable', 'string', 'max:255'],
            'estado_id' => ['nullable'],
            'select_id_estado' => ['nullable'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $nombre = trim((string) ($data['nombre'] ?? $data['txt_nombre'] ?? ''));
        $categoriaId = (int) ($data['categoria_id'] ?? $data['select_id_categoria'] ?? 0);

        if ($nombre === '' || $categoriaId <= 0) {
            return response()->json(['message' => 'Nombre y categoría son obligatorios'], 422);
        }

        $servicio = $this->servicioService->create($data, $request);

        return response()->json([
            'message' => 'Servicio creado correctamente',
            'data' => $servicio,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'categoria_id' => ['nullable', 'integer'],
            'select_id_categoria' => ['nullable', 'integer'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'txt_nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'txt_descripcion' => ['nullable', 'string'],
            'breve_desc' => ['nullable', 'string', 'max:255'],
            'estado_id' => ['nullable'],
            'select_id_estado' => ['nullable'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $nombre = trim((string) ($data['nombre'] ?? $data['txt_nombre'] ?? ''));
        $categoriaId = (int) ($data['categoria_id'] ?? $data['select_id_categoria'] ?? 0);

        if ($nombre === '' || $categoriaId <= 0) {
            return response()->json(['message' => 'Nombre y categoría son obligatorios'], 422);
        }

        $servicio = $this->servicioService->update($id, $data, $request);

        if (!$servicio) {
            return response()->json(['message' => 'Servicio no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Servicio actualizado correctamente',
            'data' => $servicio,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $servicio = $this->servicioService->delete($id, $request);

        if (!$servicio) {
            return response()->json(['message' => 'Servicio no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Servicio desactivado correctamente',
            'data' => $servicio,
        ]);
    }
}
