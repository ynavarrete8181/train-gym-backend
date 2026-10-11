<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\PaginaSistemaService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaginaSistemaController extends Controller
{
    public function __construct(private readonly PaginaSistemaService $paginaSistemaService) {}

    public function index(): JsonResponse
    {
        return ApiResponse::exito('Páginas del sistema consultadas correctamente.', $this->paginaSistemaService->listar());
    }

    public function update(Request $request, string $codigo): JsonResponse
    {
        $datos = $request->validate([
            'clave_pagina' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z][A-Za-z0-9]*Page$/'],
        ]);

        return ApiResponse::exito(
            'Página asociada correctamente.',
            $this->paginaSistemaService->asociar($codigo, $datos['clave_pagina'])
        );
    }

    public function destroy(string $codigo): JsonResponse
    {
        $this->paginaSistemaService->desasociar($codigo);

        return ApiResponse::exito('Página desasociada correctamente.');
    }
}
