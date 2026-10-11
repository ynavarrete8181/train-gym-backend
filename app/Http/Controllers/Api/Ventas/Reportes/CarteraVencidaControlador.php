<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\CarteraVencidaServicio;
use App\Services\Ventas\Reportes\Exportaciones\CarteraVencidaExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CarteraVencidaControlador extends Controller
{
    public function __construct(
        private readonly CarteraVencidaServicio $servicio,
        private readonly CarteraVencidaExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'sede_id' => 'nullable',
            'venta_numero' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'vencimiento' => 'nullable|string|max:30',
            'dias_vencidos' => 'nullable|string|max:20',
            'saldo' => 'nullable|string|max:30',
            'responsable' => 'nullable|string|max:120',
            'prioridad' => 'nullable',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte de cartera vencida consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function excel(Request $request)
    {
        $filtros = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'sede_id' => 'nullable',
            'venta_numero' => 'nullable|string|max:120',
            'cliente' => 'nullable|string|max:120',
            'vencimiento' => 'nullable|string|max:30',
            'dias_vencidos' => 'nullable|string|max:20',
            'saldo' => 'nullable|string|max:30',
            'responsable' => 'nullable|string|max:120',
            'prioridad' => 'nullable'
        ]);

        return $this->exportacion->excel($filtros, (int) $request->user()->id);
    }

}
