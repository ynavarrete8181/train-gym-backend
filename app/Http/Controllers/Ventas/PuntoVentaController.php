<?php

namespace App\Http\Controllers\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\PuntoVentaService;
use Illuminate\Http\Request;
use RuntimeException;

class PuntoVentaController extends Controller
{
    public function __construct(private PuntoVentaService $puntoVentaService)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer'],
            'forma_pago' => ['required', 'string', 'in:EFECTIVO,TARJETA,TRANSFERENCIA,QR,OTRO'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observacion' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gte:0'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'gte:0'],
            'items.*.tipo_precio' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $venta = $this->puntoVentaService->procesar($data, $request);

            return response()->json([
                'message' => 'Venta registrada correctamente',
                'data' => $venta,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
