<?php

namespace App\Http\Controllers\Api\Ventas\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Ventas\Reportes\CobrosPeriodoServicio;
use App\Services\Ventas\Reportes\Exportaciones\CobrosPeriodoExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CobrosPeriodoControlador extends Controller
{
    public function __construct(
        private readonly CobrosPeriodoServicio $servicio,
        private readonly CobrosPeriodoExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $resultado = $this->servicio->consultar($this->filtros($request, true), (int) $request->user()->id);

        return ApiResponse::exito('Reporte de cobros del período consultado.', $resultado['datos'], $resultado['meta']);
    }

    public function excel(Request $request)
    {
        return $this->exportacion->excel($this->filtros($request, false), (int) $request->user()->id);
    }

    private function filtros(Request $request, bool $paginado): array
    {
        return $request->validate([
            'columnas' => 'nullable|array',
            'columnas.*' => 'nullable|array',
            'columnas.*.*' => 'nullable|string|max:500',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'metodo_pago' => 'nullable',
            'por_completar' => 'nullable|boolean',
        ] + ($paginado ? [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ] : []));
    }
}
