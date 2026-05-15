<?php

namespace App\Http\Controllers\Inventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventarios\ProductoMovimientoService;
use Illuminate\Http\Request;
use RuntimeException;

class ProductoMovimientoController extends Controller
{
    public function __construct(private ProductoMovimientoService $productoMovimientoService)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'producto_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'tipo_movimiento' => ['nullable', 'string', 'max:30'],
            'motivo' => ['nullable', 'string', 'max:100'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        return response()->json($this->productoMovimientoService->all($filters));
    }

    public function entrada(Request $request)
    {
        $data = $this->validateMovimiento($request);

        try {
            $movimiento = $this->productoMovimientoService->registrarEntrada($data, $request);

            return response()->json([
                'message' => 'Entrada registrada correctamente',
                'data' => $movimiento,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function salida(Request $request)
    {
        $data = $this->validateMovimiento($request);

        try {
            $movimiento = $this->productoMovimientoService->registrarSalida($data, $request);

            return response()->json([
                'message' => 'Salida registrada correctamente',
                'data' => $movimiento,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function ajuste(Request $request)
    {
        $data = $this->validateMovimiento($request, true);

        try {
            $movimiento = $this->productoMovimientoService->registrarAjuste($data, $request);

            return response()->json([
                'message' => 'Ajuste registrado correctamente',
                'data' => $movimiento,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function baja(Request $request)
    {
        $data = $this->validateMovimiento($request, true);

        try {
            $movimiento = $this->productoMovimientoService->registrarBaja($data, $request);

            return response()->json([
                'message' => 'Baja registrada correctamente',
                'data' => $movimiento,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function inventarioInicial(Request $request, int $id)
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer'],
            'cantidad' => ['nullable', 'numeric', 'gt:0'],
            'stock_minimo' => ['nullable', 'numeric', 'gte:0'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'costo_unitario' => ['nullable', 'numeric', 'gte:0'],
            'precio_unitario' => ['nullable', 'numeric', 'gte:0'],
            'observacion' => ['nullable', 'string'],
            'lotes' => ['nullable', 'array'],
            'lotes.*.codigo_lote' => ['required_with:lotes', 'string', 'max:100'],
            'lotes.*.fecha_elaboracion' => ['required_with:lotes', 'date'],
            'lotes.*.fecha_vencimiento' => ['nullable', 'date'],
            'lotes.*.cantidad_inicial' => ['required_with:lotes', 'numeric', 'gt:0'],
        ]);

        $data['producto_id'] = $id;

        try {
            $inventario = $this->productoMovimientoService->registrarInventarioInicial($data, $request);

            return response()->json([
                'message' => 'Inventario inicial registrado correctamente',
                'data' => $inventario,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function validateMovimiento(Request $request, bool $observacionObligatoria = false): array
    {
        $rules = [
            'producto_id' => ['required', 'integer'],
            'sede_id' => ['required', 'integer'],
            'lote_id' => ['nullable', 'integer'],
            'codigo_lote' => ['nullable', 'string', 'max:100'],
            'fecha_elaboracion' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'motivo' => ['required', 'string', 'max:100'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'costo_unitario' => ['nullable', 'numeric', 'gte:0'],
            'precio_unitario' => ['nullable', 'numeric', 'gte:0'],
            'referencia_tipo' => ['nullable', 'string', 'max:50'],
            'referencia_id' => ['nullable', 'integer'],
            'observacion' => ['nullable', 'string'],
        ];

        if ($observacionObligatoria) {
            $rules['observacion'] = ['required', 'string'];
        }

        return $request->validate($rules);
    }
}
