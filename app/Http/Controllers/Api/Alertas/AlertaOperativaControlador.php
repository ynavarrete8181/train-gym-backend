<?php

namespace App\Http\Controllers\Api\Alertas;

use App\Http\Controllers\Controller;
use App\Services\Alertas\AlertaOperativaServicio;
use App\Services\Alertas\Exportaciones\AlertasOperativasExportacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AlertaOperativaControlador extends Controller
{
    public function __construct(
        private readonly AlertaOperativaServicio $servicio,
        private readonly AlertasOperativasExportacionServicio $exportacion,
    ) {}

    public function index(Request $request)
    {
        $filtros = $this->validar($request);

        $resultado = $this->servicio->listar(
            $filtros,
            (int) $request->user()->id,
        );

        return ApiResponse::exito(
            'Alertas operativas consultadas.',
            $resultado['datos'],
            $resultado['meta'],
        );
    }

    public function procesar(Request $request)
    {
        return ApiResponse::exito(
            'Alertas operativas actualizadas.',
            $this->servicio->procesar(),
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
            'id' => 'nullable|integer|min:1',
            'busqueda' => 'nullable|string|max:160',
            'estado' => 'nullable',
            'tipo' => 'nullable',
            'nivel' => 'nullable',
            'sede_id' => 'nullable',
        ];

        if ($paginado) {
            $reglas['page'] = 'nullable|integer|min:1';
            $reglas['per_page'] = 'nullable|integer|in:5,10,25,50';
        }

        return $request->validate($reglas);
    }
}
