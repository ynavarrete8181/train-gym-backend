<?php

namespace App\Http\Controllers\Api\Institucional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Institucional\GuardarCarreraAreaRequest;
use App\Http\Requests\Institucional\GuardarSedeRequest;
use App\Http\Requests\Institucional\GuardarUnidadRequest;
use App\Services\Institucional\ContextoOperativoService;
use App\Services\Institucional\EstructuraInstitucionalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstructuraInstitucionalController extends Controller
{
    public function __construct(
        private readonly EstructuraInstitucionalService $service,
        private readonly ContextoOperativoService $contextoOperativoService,
    ) {}

    public function index(): JsonResponse
    {
        $catalogos = $this->service->catalogos();
        $catalogos['contextos'] = collect([
            ...($catalogos['contextos'] ?? []),
            ...$this->contextoOperativoService->contextosSede(),
        ])->sortBy('nombre')->values()->all();

        return ApiResponse::exito('Estructura operativa consultada.', $catalogos);
    }

    public function sede(GuardarSedeRequest $r, ?int $id = null): JsonResponse
    {
        $resultado = $this->service->guardarSede($r->validated(), $id);
        $this->contextoOperativoService->asegurarContextosSede();

        return ApiResponse::exito('Sede guardada.', $resultado);
    }

    public function unidad(GuardarUnidadRequest $r, ?int $id = null): JsonResponse
    {
        $resultado = $this->service->guardarUnidad($r->validated(), $id);
        $this->contextoOperativoService->asegurarContextosSede();

        return ApiResponse::exito('Unidad guardada.', $resultado);
    }

    public function carreraArea(GuardarCarreraAreaRequest $r, ?int $id = null): JsonResponse
    {
        $resultado = $this->service->guardarCarreraArea($r->validated(), $id);
        $this->contextoOperativoService->asegurarContextosSede();

        return ApiResponse::exito('Carrera o área guardada.', $resultado);
    }

    public function estado(Request $r, string $tipo, int $id): JsonResponse
    {
        $d = $r->validate(['activo' => ['required', 'boolean']]);
        $this->service->cambiarEstado($tipo, $id, $d['activo']);
        $this->contextoOperativoService->asegurarContextosSede();

        return ApiResponse::exito('Estado actualizado.');
    }
}
