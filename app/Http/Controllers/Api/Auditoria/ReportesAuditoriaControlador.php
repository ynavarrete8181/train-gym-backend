<?php

namespace App\Http\Controllers\Api\Auditoria;

use App\Http\Controllers\Controller;
use App\Services\Auditoria\ReportesAuditoriaServicio;
use App\Services\Auditoria\Exportaciones\ReportesAuditoriaExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReportesAuditoriaControlador extends Controller
{
    public function __construct(
        private readonly ReportesAuditoriaServicio $servicio,
        private readonly ReportesAuditoriaExportacionServicio $exportacion,
    ) {}

    public function index(Request $request)
    {
        $filtros = $this->validar($request);
        $resultado = $this->servicio->consultar($filtros);

        return ApiResponse::exito(
            'Reporte de auditoría consultado.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function excel(Request $request)
    {
        return $this->exportacion->excel(
            $this->validar($request, false),
            (int) $request->user()->id,
        );
    }

    private function validar(Request $request, bool $paginado = true): array
    {
        $reglas = [
            'tipo' => 'nullable|in:USUARIO,MODULO,CRITICOS,ACCESOS_FALLIDOS,ERRORES_RECURRENTES',
            'busqueda' => 'nullable|string|max:160',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ];

        if ($paginado) {
            $reglas['page'] = 'nullable|integer|min:1';
            $reglas['per_page'] = 'nullable|integer|in:5,10,25,50';
        }

        return $request->validate($reglas);
    }
}
