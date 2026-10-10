<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\CobrosMetodoPagoServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CobrosMetodoPagoControlador extends Controller
{
    public function __construct(private readonly CobrosMetodoPagoServicio $servicio)
    {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'metodo_pago' => 'nullable',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de cobros por método de pago consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }
}
