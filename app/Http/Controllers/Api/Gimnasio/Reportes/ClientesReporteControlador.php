<?php

namespace App\Http\Controllers\Api\Gimnasio\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\Reportes\ClientesReporteServicio;
use App\Services\Gimnasio\Reportes\Exportaciones\ClientesReporteExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ClientesReporteControlador extends Controller
{
    public function __construct(
        private readonly ClientesReporteServicio $servicio,
        private readonly ClientesReporteExportacionServicio $exportacion,
    ) {
    }

    public function index(Request $request)
    {
        $resultado = $this->servicio->consultar($this->filtros($request, true), (int) $request->user()->id);

        return ApiResponse::exito('Reporte de clientes consultado.', $resultado['datos'], $resultado['meta']);
    }

    public function excel(Request $request)
    {
        return $this->exportacion->excel($this->filtros($request, false), (int) $request->user()->id);
    }

    private function filtros(Request $request, bool $paginado): array
    {
        return $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'columnas' => 'nullable|array',
            'columnas.*' => 'nullable|array',
            'columnas.*.*' => 'nullable|string|max:500',
            'sede_id' => 'nullable',
            'tarjeta' => 'nullable|in:ACTIVOS,MEMBRESIA_ACTIVA,CON_DEUDA,PROSPECTOS,SIN_MEMBRESIA,SIN_CEDULA',
        ] + ($paginado ? [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ] : []));
    }
}
