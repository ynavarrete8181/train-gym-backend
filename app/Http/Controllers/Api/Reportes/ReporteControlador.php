<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Reportes\ReporteServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReporteControlador extends Controller
{
    public function __construct(private readonly ReporteServicio $reportes)
    {
    }

    public function disponibles(Request $request)
    {
        return $this->respuesta('Reportes disponibles consultados.', $this->reportes->disponibles($request->all()));
    }

    public function historial(Request $request)
    {
        return $this->respuesta('Historial de reportes consultado.', $this->reportes->historial($request->all()));
    }

    public function generar(Request $request)
    {
        $datos = $request->validate([
            'codigo' => 'required|string|exists:pgsql.reportes.definiciones,codigo',
            'formato' => 'nullable|string|in:VISTA,PDF,EXCEL',
            'filtros' => 'nullable|array',
        ]);

        return ApiResponse::exito('Reporte generado.', (array) $this->reportes->generar($datos, $request->user()?->id), [], 201);
    }

    private function respuesta(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->reportes->opcionesFiltro(),
        ]);
    }
}
