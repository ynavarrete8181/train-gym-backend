<?php

namespace App\Http\Controllers\Api\Resultados;

use App\Http\Controllers\Controller;
use App\Services\Resultados\ResultadoServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ResultadoControlador extends Controller
{
    public function __construct(private readonly ResultadoServicio $resultados)
    {
    }

    public function resumen()
    {
        return ApiResponse::exito('Resumen operativo consultado.', $this->resultados->resumen());
    }

    public function asistencia(Request $request) { return $this->respuesta('Resultados de asistencia consultados.', $this->resultados->asistencia($request->all())); }
    public function ventas(Request $request) { return $this->respuesta('Resultados de ventas consultados.', $this->resultados->ventas($request->all())); }
    public function progreso(Request $request) { return $this->respuesta('Resultados de progreso consultados.', $this->resultados->progreso($request->all())); }

    private function respuesta(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->resultados->opcionesFiltro(),
        ]);
    }
}
