<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Queries\Staff\StaffQuery;
use App\Services\Staff\StaffService;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(
        private StaffQuery $query,
        private StaffService $service
    ) {
    }

    public function perfiles(Request $request)
    {
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
            'tipo_staff' => ['nullable', 'string', 'max:30'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json($this->query->perfiles($filters));
    }

    public function crearPerfil(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'usuario_id' => ['nullable', 'integer'],
            'tipo_staff' => ['required', 'string', 'max:30'],
            'especialidad' => ['nullable', 'string', 'max:160'],
            'estado' => ['nullable', 'string', 'max:30'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observaciones' => ['nullable', 'string'],
            'sedes' => ['nullable', 'array'],
            'sedes.*' => ['integer'],
        ]);

        return response()->json([
            'message' => 'Perfil de staff creado correctamente.',
            'data' => $this->service->crearPerfil($request, $data),
        ], 201);
    }

    public function turnos(Request $request)
    {
        $filters = $request->validate([
            'coach_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'dia_semana' => ['nullable', 'integer', 'between:1,7'],
        ]);

        return response()->json($this->query->turnos($filters));
    }

    public function crearTurno(Request $request)
    {
        $data = $request->validate([
            'coach_id' => ['required', 'integer'],
            'sede_id' => ['required', 'integer'],
            'dia_semana' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'capacidad_atencion' => ['nullable', 'integer', 'min:1'],
            'activo' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'message' => 'Turno de coach creado correctamente.',
            'data' => $this->service->crearTurno($request, $data),
        ], 201);
    }

    public function clientes(Request $request)
    {
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
            'coach_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json($this->query->seguimientoClientes($filters));
    }

    public function misClientes(Request $request)
    {
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
            'sede_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $filters['usuario_id'] = $request->user()?->id ?: -1;
        $filters['estado'] = $filters['estado'] ?? 'ACTIVO';

        return response()->json($this->query->clientesAsignados($filters));
    }

    public function asignarCliente(Request $request)
    {
        $data = $request->validate([
            'coach_id' => ['required', 'integer'],
            'persona_id' => ['required', 'integer'],
            'sede_id' => ['required', 'integer'],
            'turno_recurrente_id' => ['nullable', 'integer'],
            'tipo_asignacion' => ['nullable', 'string', 'max:30'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['nullable', 'string', 'max:30'],
            'objetivo' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => 'Cliente asignado al coach correctamente.',
            'data' => $this->service->asignarCliente($request, $data),
        ], 201);
    }

    public function finalizarCliente(Request $request, int $id)
    {
        $this->service->finalizarAsignacion($request, $id);

        return response()->json([
            'message' => 'Asignación finalizada correctamente.',
        ]);
    }

    public function actualizarObservaciones(Request $request, int $id)
    {
        $data = $request->validate([
            'observaciones' => ['nullable', 'string'],
            'objetivo' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => 'Seguimiento actualizado correctamente.',
            'data' => $this->service->actualizarObservaciones($request, $id, $data),
        ]);
    }
}
