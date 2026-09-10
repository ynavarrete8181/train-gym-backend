<?php

namespace App\Http\Controllers\Api\Auditoria;

use App\Http\Controllers\Controller;
use App\Services\Auditoria\AuditoriaServicio;
use App\Services\Logs\LogSistemaService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuditoriaControlador extends Controller
{
    public function __construct(
        private readonly AuditoriaServicio $auditoria,
        private readonly LogSistemaService $logs,
    ) {
    }

    public function eventos(Request $request)
    {
        $paginador = $this->auditoria->listarEventos($request->all());

        return ApiResponse::exito('Registro de actividad consultado.', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->auditoria->opcionesFiltro(),
        ]);
    }

    public function accesos(Request $request)
    {
        $paginador = $this->auditoria->listarAccesos($request->all());

        return ApiResponse::exito('Accesos al sistema consultados.', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->auditoria->opcionesFiltro(),
        ]);
    }

    public function resumen(Request $request)
    {
        return ApiResponse::exito('Resumen de auditoría consultado.', $this->auditoria->resumen($request->all()));
    }

    public function logs(Request $request)
    {
        $paginador = $this->logs->listarEventos($request->all());

        return ApiResponse::exito('Errores del sistema consultados.', $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->logs->opcionesFiltro(),
        ]);
    }
}
