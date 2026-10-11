<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\ProductosServiciosVendidosServicio;
use App\Services\Ventas\Reportes\Exportaciones\ProductosServiciosVendidosExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductosServiciosVendidosControlador extends Controller
{
    public function __construct(
        private readonly ProductosServiciosVendidosServicio $servicio,
        private readonly ProductosServiciosVendidosExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'tipo_item' => 'nullable',
            'item' => 'nullable|string|max:180',
            'ventas' => 'nullable|string|max:20',
            'cantidad' => 'nullable|string|max:30',
            'precio_promedio' => 'nullable|string|max:30',
            'total_vendido' => 'nullable|string|max:30',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de productos y servicios vendidos consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function excel(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'tipo_item' => 'nullable',
            'item' => 'nullable|string|max:180',
            'ventas' => 'nullable|string|max:20',
            'cantidad' => 'nullable|string|max:30',
            'precio_promedio' => 'nullable|string|max:30',
            'total_vendido' => 'nullable|string|max:30'
        ]);

        return $this->exportacion->excel($filtros, (int) $request->user()->id);
    }

}
