<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\ResumenComercialServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ResumenComercialControlador extends Controller
{
    public function __construct(private readonly ResumenComercialServicio $servicio)
    {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'transacciones' => 'nullable|string|max:20',
            'total_ventas' => 'nullable|string|max:30',
        ]);

        return ApiResponse::exito(
            'Resumen comercial consultado.',
            $this->servicio->consultar($filtros, (int) $request->user()->id),
        );
    }
}
