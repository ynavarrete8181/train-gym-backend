<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventarios\ProductoService;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    public function __construct(private ProductoService $productoService)
    {
    }

    public function index()
    {
        return response()->json($this->productoService->all());
    }

    public function show(int $id)
    {
        $producto = $this->productoService->find($id);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($producto);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(true));

        $producto = $this->productoService->create($validated, $request);

        return response()->json($producto, 201);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate($this->rules(false));

        $producto = $this->productoService->update($id, $validated, $request);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($producto);
    }

    public function destroy(Request $request, int $id)
    {
        $producto = $this->productoService->delete($id, $request);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Producto desactivado correctamente',
            'data' => $producto,
        ]);
    }

    public function sedes()
    {
        return response()->json($this->productoService->sedes());
    }

    private function rules(bool $isCreate): array
    {
        $rules = [
            'codigo' => ['nullable', 'string', 'max:100'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'categoria_id' => ['required', 'integer', 'min:1'],
            'marca' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'codigo_barras' => ['nullable', 'string', 'max:255'],
            'unidad_medida' => ['required', 'string', 'max:100'],
            'controla_stock' => ['nullable', 'boolean'],
            'permite_decimales' => ['nullable', 'boolean'],
            'maneja_lotes' => ['nullable', 'boolean'],
            'maneja_vencimiento' => ['nullable', 'boolean'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'stock_maximo' => ['nullable', 'numeric', 'min:0'],
            'estado' => ['nullable', 'integer', 'in:0,1'],
            'imagen_url' => ['nullable', 'string', 'max:2048'],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_imagen' => ['nullable', 'boolean'],
            'precio_costo' => ['nullable', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'sede_id' => ['nullable', 'integer', 'min:1'],
            'stock_inicial' => ['nullable', 'numeric', 'min:0'],
            'stock_minimo_sede' => ['nullable', 'numeric', 'min:0'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
        ];

        return $rules;
    }
}
