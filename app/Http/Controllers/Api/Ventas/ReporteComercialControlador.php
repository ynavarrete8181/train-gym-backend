<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Services\Ventas\ReporteComercialServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReporteComercialControlador extends Controller
{
    public function __construct(private readonly ReporteComercialServicio $reportes)
    {
    }

    public function index(Request $request)
    {
        $datos = $request->validate([
            'busqueda' => 'nullable|string|max:120',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'tipo_venta' => 'nullable',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->reportes->consultar($datos, (int) $request->user()->id);

        return ApiResponse::exito(
            'Reporte comercial consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function analitica(Request $request)
    {
        $datos = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
        ]);

        return ApiResponse::exito(
            'Analítica comercial consultada.',
            $this->reportes->analitica($datos, (int) $request->user()->id),
        );
    }
}
