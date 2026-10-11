<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\HorarioEntrenadorServicio;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HorarioEntrenadorControlador extends Controller
{
    public function __construct(private readonly HorarioEntrenadorServicio $servicio) {}

    public function index(int $entrenadorId): JsonResponse
    {
        return ApiResponse::exito('Horarios del entrenador consultados.', $this->servicio->listar($entrenadorId));
    }

    public function catalogos(): JsonResponse
    {
        return ApiResponse::exito('Catálogos de horario consultados.', $this->servicio->catalogos());
    }

    public function store(Request $request, int $entrenadorId): JsonResponse
    {
        return $this->guardar($request, $entrenadorId, null);
    }

    public function update(Request $request, int $entrenadorId, int $id): JsonResponse
    {
        return $this->guardar($request, $entrenadorId, $id);
    }

    private function guardar(Request $request, int $entrenadorId, ?int $id): JsonResponse
    {
        $datos = $request->validate([
            'tipo_horario' => 'required|string|in:INSTITUCIONAL,PERSONALIZADO',
            'jornada_id' => 'nullable|integer|exists:pgsql.gimnasio.jornadas,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'activo' => 'boolean',
            'capacidad' => 'required|integer|min:1|max:500',
            'observaciones' => 'nullable|string|max:1000',
            'franjas' => 'required|array|min:1',
            'franjas.*.dia_semana' => 'required|string|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'franjas.*.sede_id' => 'required|integer|exists:pgsql.institucional.sedes,id_sede',
            'franjas.*.hora_inicio' => 'required|date_format:H:i',
            'franjas.*.hora_fin' => 'required|date_format:H:i',
            'recesos' => 'nullable|array',
            'recesos.*.dia_semana' => 'required|string|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'recesos.*.tipo' => 'required|string|in:DESAYUNO,ALMUERZO,MERIENDA,PAUSA,OTRO',
            'recesos.*.descripcion' => 'nullable|string|max:150',
            'recesos.*.hora_inicio' => 'required|date_format:H:i',
            'recesos.*.hora_fin' => 'required|date_format:H:i',
        ]);

        $resultado = $this->servicio->guardar($entrenadorId, $datos, $id);

        return ApiResponse::exito(
            $id ? 'Horario del entrenador actualizado.' : 'Horario del entrenador creado.',
            $resultado,
            [],
            $id ? 200 : 201,
        );
    }
}
