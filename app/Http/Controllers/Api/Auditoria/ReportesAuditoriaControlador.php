<?php

namespace App\Http\Controllers\Api\Auditoria;

use App\Http\Controllers\Controller;
use App\Services\Auditoria\ReportesAuditoriaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReportesAuditoriaControlador extends Controller
{
    public function __construct(
        private readonly ReportesAuditoriaServicio $servicio,
    ) {}

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'tipo' => 'nullable|in:USUARIO,MODULO,CRITICOS,ACCESOS_FALLIDOS,ERRORES_RECURRENTES',
            'busqueda' => 'nullable|string|max:160',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);

        $resultado = $this->servicio->consultar($filtros);

        return ApiResponse::exito(
            'Reporte de auditoría consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }
}
