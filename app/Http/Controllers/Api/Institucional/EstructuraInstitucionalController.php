<?php

namespace App\Http\Controllers\Api\Institucional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Institucional\GuardarCarreraAreaRequest;
use App\Http\Requests\Institucional\GuardarSedeRequest;
use App\Http\Requests\Institucional\GuardarUnidadRequest;
use App\Services\Institucional\EstructuraInstitucionalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstructuraInstitucionalController extends Controller
{
    public function __construct(private readonly EstructuraInstitucionalService $service) {}

    public function index(): JsonResponse
    {
        return ApiResponse::exito('Estructura operativa consultada.', $this->service->catalogos());
    }

    public function sede(GuardarSedeRequest $r, ?int $id = null): JsonResponse
    {
        return ApiResponse::exito('Sede guardada.', $this->service->guardarSede($r->validated(), $id));
    }

    public function unidad(GuardarUnidadRequest $r, ?int $id = null): JsonResponse
    {
        return ApiResponse::exito('Unidad guardada.', $this->service->guardarUnidad($r->validated(), $id));
    }

    public function carreraArea(GuardarCarreraAreaRequest $r, ?int $id = null): JsonResponse
    {
        return ApiResponse::exito('Carrera o área guardada.', $this->service->guardarCarreraArea($r->validated(), $id));
    }

    public function estado(Request $r, string $tipo, int $id): JsonResponse
    {
        $d = $r->validate(['activo' => ['required', 'boolean']]);
        $this->service->cambiarEstado($tipo, $id, $d['activo']);

        return ApiResponse::exito('Estado actualizado.');
    }
}
