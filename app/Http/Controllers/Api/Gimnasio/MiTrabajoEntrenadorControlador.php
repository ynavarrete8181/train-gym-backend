<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\AsignacionEntrenadorClienteServicio;
use App\Services\Gimnasio\EntrenadorServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MiTrabajoEntrenadorControlador extends Controller
{
    public function __construct(
        private readonly EntrenadorServicio $entrenadores,
        private readonly AsignacionEntrenadorClienteServicio $asignaciones,
    ) {}

    public function deportistas(Request $request): JsonResponse
    {
        $entrenador = $this->resolverEntrenador($request);

        return ApiResponse::exito(
            'Deportistas asignados consultados.',
            $this->asignaciones->listarPorEntrenador((int) $entrenador->id, 'ACTIVO'),
        );
    }

    public function agenda(Request $request): JsonResponse
    {
        $entrenador = $this->resolverEntrenador($request);

        return ApiResponse::exito(
            'Agenda del entrenador consultada.',
            $this->entrenadores->listarTurnos((int) $entrenador->id),
        );
    }

    private function resolverEntrenador(Request $request): object
    {
        $entrenador = $this->entrenadores->obtenerPorUsuario((int) $request->user()->id);

        abort_if(! $entrenador, 403, 'El usuario autenticado no tiene un perfil de entrenador activo.');

        return $entrenador;
    }
}
