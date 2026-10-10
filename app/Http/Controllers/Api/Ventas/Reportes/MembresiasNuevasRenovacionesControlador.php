<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\MembresiasNuevasRenovacionesServicio;
use App\Services\Ventas\Reportes\Exportaciones\MembresiasNuevasRenovacionesExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MembresiasNuevasRenovacionesControlador extends Controller
{
    public function __construct(
        private readonly MembresiasNuevasRenovacionesServicio $servicio,
        private readonly MembresiasNuevasRenovacionesExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'fecha_movimiento' => 'nullable|string|max:30',
            'sede_id' => 'nullable',
            'tipo_movimiento' => 'nullable',
            'codigo_contrato' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
            'modalidad' => 'nullable|string|max:120',
            'numero_periodo' => 'nullable|string|max:20',
            'estado_periodo' => 'nullable',
            'precio' => 'nullable|string|max:30',
            'cobrado' => 'nullable|string|max:30',
            'saldo' => 'nullable|string|max:30',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de membresías nuevas y renovaciones consultado.',
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
            'fecha_movimiento' => 'nullable|string|max:30',
            'sede_id' => 'nullable',
            'tipo_movimiento' => 'nullable',
            'codigo_contrato' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
            'modalidad' => 'nullable|string|max:120',
            'numero_periodo' => 'nullable|string|max:20',
            'estado_periodo' => 'nullable',
            'precio' => 'nullable|string|max:30',
            'cobrado' => 'nullable|string|max:30',
            'saldo' => 'nullable|string|max:30'
        ]);

        return $this->exportacion->excel($filtros, (int) $request->user()->id);
    }

}
