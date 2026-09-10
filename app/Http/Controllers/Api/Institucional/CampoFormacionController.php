<?php

namespace App\Http\Controllers\Api\Institucional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Institucional\GuardarCampoAmplioRequest;
use App\Services\Institucional\CampoFormacionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampoFormacionController extends Controller
{
    public function __construct(private readonly CampoFormacionService $service) {}

    public function index(): JsonResponse
    {
        return ApiResponse::exito('Campos amplios consultados.', $this->service->listar());
    }

    public function amplio(GuardarCampoAmplioRequest $request, ?int $id = null): JsonResponse
    {
        return ApiResponse::exito('Campo amplio guardado.', $this->service->guardarAmplio($request->validated(), $id));
    }

    public function estado(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);
        $this->service->cambiarEstado($id, $datos['activo']);
        return ApiResponse::exito('Estado actualizado.');
    }
}
