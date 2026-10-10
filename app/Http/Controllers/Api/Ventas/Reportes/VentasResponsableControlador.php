<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\VentasResponsableServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class VentasResponsableControlador extends Controller
{
    public function __construct(private readonly VentasResponsableServicio $servicio)
    {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'responsable_id' => 'nullable',
            'responsable' => 'nullable|string|max:120',
            'ventas' => 'nullable|string|max:20',
            'clientes' => 'nullable|string|max:20',
            'total_ventas' => 'nullable|string|max:30',
            'total_cobrado' => 'nullable|string|max:30',
            'saldo' => 'nullable|string|max:30',
            'ticket_promedio' => 'nullable|string|max:30',
            'porcentaje_cobrado' => 'nullable|string|max:30',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de ventas por responsable comercial consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }
}
