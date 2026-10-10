<?php

namespace App\Http\Controllers\Api\Auditoria;

use App\Http\Controllers\Controller;
use App\Services\Auditoria\TrazabilidadComercialServicio;
use App\Services\Auditoria\Exportaciones\TrazabilidadComercialExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TrazabilidadComercialControlador extends Controller
{
    public function __construct(
        private readonly TrazabilidadComercialServicio $servicio,
        private readonly TrazabilidadComercialExportacionServicio $exportacion,
    ) {}

    public function index(Request $request)
    {
        $filtros = $this->validar($request);
        $resultado = $this->servicio->consultar($filtros);

        return ApiResponse::exito(
            'Trazabilidad comercial consultada.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function excel(Request $request)
    {
        return $this->exportacion->excel($this->validar($request), (int) $request->user()->id);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'busqueda' => 'nullable|string|max:160',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
            'proceso' => 'nullable',
            'accion' => 'nullable',
            'usuario' => 'nullable',
            'rol' => 'nullable',
            'referencia' => 'nullable|string|max:160',
            'descripcion' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:5,10,25,50',
        ]);
    }
}
