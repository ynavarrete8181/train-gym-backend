<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\AsignacionEntrenadorClienteServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsignacionEntrenadorClienteControlador extends Controller
{
    public function __construct(private readonly AsignacionEntrenadorClienteServicio $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'entrenador_id' => 'nullable|integer|exists:pgsql.gimnasio.entrenadores,id',
            'deportista_id' => 'nullable|integer|exists:pgsql.gimnasio.deportistas,id',
            'estado' => 'nullable|string',
        ]);

        abort_if(empty($datos['entrenador_id']) && empty($datos['deportista_id']), 422, 'Debe indicar entrenador_id o deportista_id.');

        $estado = array_key_exists('estado', $datos) ? $datos['estado'] : 'ACTIVO';
        $estado = $estado === 'TODOS' ? null : $estado;

        $items = ! empty($datos['entrenador_id'])
            ? $this->servicio->listarPorEntrenador((int) $datos['entrenador_id'], $estado)
            : $this->servicio->listarPorDeportista((int) $datos['deportista_id'], $estado);

        return ApiResponse::exito('Asignaciones consultadas.', $items);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'entrenador_id' => 'required|integer|exists:pgsql.gimnasio.entrenadores,id',
            'deportista_id' => 'required|integer|exists:pgsql.gimnasio.deportistas,id',
            'horario_bloque_id' => 'nullable|integer|exists:pgsql.gimnasio.horario_bloques,id',
            'membresia_id' => 'nullable|integer|exists:pgsql.gimnasio.membresias,id',
            'tipo_asignacion' => 'nullable|string|max:30',
            'fecha_inicio' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        $asignacion = $this->servicio->asignar($datos);

        return ApiResponse::exito('Cliente asignado correctamente.', (array) $asignacion, [], 201);
    }

    public function finalizar(int $id): JsonResponse
    {
        $asignacion = $this->servicio->finalizar($id);

        return ApiResponse::exito('Asignación finalizada correctamente.', (array) $asignacion);
    }
}
