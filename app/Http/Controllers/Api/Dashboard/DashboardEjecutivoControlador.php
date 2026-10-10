<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardEjecutivoServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DashboardEjecutivoControlador extends Controller
{
    public function __construct(
        private readonly DashboardEjecutivoServicio $servicio,
    ) {}

    public function __invoke(Request $request)
    {
        $filtros = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'sede_id' => 'nullable',
        ]);

        return ApiResponse::exito(
            'Dashboard ejecutivo consultado.',
            $this->servicio->consultar($filtros, (int) $request->user()->id),
        );
    }
}
