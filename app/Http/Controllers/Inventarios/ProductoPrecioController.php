<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventarios\ProductoPrecioService;
use Illuminate\Http\Request;
use RuntimeException;

class ProductoPrecioController extends Controller
{
    public function __construct(private ProductoPrecioService $productoPrecioService)
    {
    }

    public function index(int $id)
    {
        return response()->json($this->productoPrecioService->byProducto($id));
    }

    public function store(Request $request, int $id)
    {
        $validated = $request->validate([
            'tipo_precio' => ['required', 'string', 'max:30'],
            'sede_id' => ['nullable', 'integer'],
            'monto' => ['required', 'numeric', 'gte:0'],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date'],
            'estado' => ['nullable', 'integer', 'in:0,1'],
        ]);

        try {
            $precio = $this->productoPrecioService->create($id, $validated, $request);
            return response()->json($precio, 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'tipo_precio' => ['required', 'string', 'max:30'],
            'sede_id' => ['nullable', 'integer'],
            'monto' => ['required', 'numeric', 'gte:0'],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date'],
            'estado' => ['nullable', 'integer', 'in:0,1'],
        ]);

        try {
            $precio = $this->productoPrecioService->update($id, $validated, $request);

            if (!$precio) {
                return response()->json(['message' => 'Precio no encontrado'], 404);
            }

            return response()->json($precio);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id)
    {
        $precio = $this->productoPrecioService->delete($id, $request);

        if (!$precio) {
            return response()->json(['message' => 'Precio no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Precio desactivado correctamente',
            'data' => $precio,
        ]);
    }
}
