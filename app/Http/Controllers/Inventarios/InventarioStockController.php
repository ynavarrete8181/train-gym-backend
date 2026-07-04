<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventarios\ProductoMovimientoService;
use Illuminate\Http\Request;
use RuntimeException;

class InventarioStockController extends Controller
{
    public function __construct(private ProductoMovimientoService $productoMovimientoService)
    {
    }

    public function index(int $id)
    {
        return response()->json($this->productoMovimientoService->stockPorProducto($id));
    }

    public function destroy(Request $request, int $id, int $stockId)
    {
        try {
            $deleted = $this->productoMovimientoService->eliminarInventarioInicial($id, $stockId, $request);

            return response()->json([
                'message' => 'Inventario inicial eliminado correctamente',
                'data' => $deleted,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
