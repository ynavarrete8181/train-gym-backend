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
            'entrenador_id' => 'nullable|integer|exists:pgsql.entrenamiento.entrenadores,id',
            'deportista_id' => 'nullable|integer|exists:pgsql.clientes.deportistas,id',
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
            'entrenador_id' => 'required|integer|exists:pgsql.entrenamiento.entrenadores,id',
            'deportista_id' => 'required|integer|exists:pgsql.clientes.deportistas,id',
            'entrenador_horario_id' => 'required|integer|exists:pgsql.agenda.entrenador_horarios,id',
            'horario_bloque_id' => 'nullable|integer|exists:pgsql.agenda.horario_bloques,id',
            'membresia_id' => 'nullable|integer|exists:pgsql.membresias.membresias,id',
            'tipo_asignacion' => 'nullable|string|max:30',
            'fecha_inicio' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        $asignacion = $this->servicio->asignar($datos);

        return ApiResponse::exito('Cliente asignado correctamente.', (array) $asignacion, [], 201);
    }

    public function actualizarObservaciones(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'observaciones' => 'nullable|string|max:2000',
        ]);

        $asignacion = $this->servicio->actualizarObservaciones(
            $id,
            $datos['observaciones'] ?? null
        );

        return ApiResponse::exito('Observaciones actualizadas correctamente.', (array) $asignacion);
    }

    public function inactivar(int $id): JsonResponse
    {
        $asignacion = $this->servicio->inactivar($id);

        return ApiResponse::exito(
            'Asignación inactivada correctamente. El registro se conserva para trazabilidad.',
            (array) $asignacion
        );
    }

    public function finalizar(int $id): JsonResponse
    {
        $asignacion = $this->servicio->finalizar($id);

        return ApiResponse::exito('Asignación finalizada correctamente.', (array) $asignacion);
    }
}
