<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\VentasPeriodoServicio;
use App\Services\Ventas\Reportes\Exportaciones\VentasPeriodoExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class VentasPeriodoControlador extends Controller
{
    public function __construct(
        private readonly VentasPeriodoServicio $servicio,
        private readonly VentasPeriodoExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'fecha' => 'nullable|string|max:30',
            'sede_id' => 'nullable',
            'venta_numero' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'tipo_venta' => 'nullable',
            'estado' => 'nullable',
            'responsable' => 'nullable|string|max:120',
            'total' => 'nullable|string|max:30',
            'cobrado' => 'nullable|string|max:30',
            'saldo' => 'nullable|string|max:30',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de ventas por período consultado.',
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
            'fecha' => 'nullable|string|max:30',
            'sede_id' => 'nullable',
            'venta_numero' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'tipo_venta' => 'nullable',
            'estado' => 'nullable',
            'responsable' => 'nullable|string|max:120',
            'total' => 'nullable|string|max:30',
            'cobrado' => 'nullable|string|max:30',
            'saldo' => 'nullable|string|max:30'
        ]);

        return $this->exportacion->excel($filtros, (int) $request->user()->id);
    }

}
