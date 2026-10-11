<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\MembresiasPorVencerServicio;
use App\Services\Ventas\Reportes\Exportaciones\MembresiasPorVencerExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MembresiasPorVencerControlador extends Controller
{
    public function __construct(
        private readonly MembresiasPorVencerServicio $servicio,
        private readonly MembresiasPorVencerExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'vence_desde' => 'nullable|date',
            'vence_hasta' => 'nullable|date|after_or_equal:vence_desde',
            'sede_id' => 'nullable',
            'codigo_contrato' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
            'modalidad' => 'nullable|string|max:120',
            'numero_periodo' => 'nullable|string|max:20',
            'fecha_fin' => 'nullable|string|max:30',
            'dias_restantes' => 'nullable|string|max:20',
            'renovable' => 'nullable',
            'estado_periodo' => 'nullable',
            'saldo' => 'nullable|string|max:30',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de membresías por vencer consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function excel(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'vence_desde' => 'nullable|date',
            'vence_hasta' => 'nullable|date|after_or_equal:vence_desde',
            'sede_id' => 'nullable',
            'codigo_contrato' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
            'modalidad' => 'nullable|string|max:120',
            'numero_periodo' => 'nullable|string|max:20',
            'fecha_fin' => 'nullable|string|max:30',
            'dias_restantes' => 'nullable|string|max:20',
            'renovable' => 'nullable',
            'estado_periodo' => 'nullable',
            'saldo' => 'nullable|string|max:30'
        ]);

        return $this->exportacion->excel($filtros, (int) $request->user()->id);
    }

}
