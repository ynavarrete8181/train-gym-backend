<?php

namespace App\Http\Controllers\Reservas;

use App\Http\Controllers\Controller;
use App\Queries\Reservas\ReservaQuery;
use App\Services\Reservas\ReservaService;
use Illuminate\Http\Request;

class ReservaController extends Controller
{
    public function __construct(
        private ReservaQuery $query,
        private ReservaService $service
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'persona_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'coach_usuario_id' => ['nullable', 'integer'],
            'membresia_id' => ['nullable', 'integer'],
            'fecha' => ['nullable', 'date'],
            'estado' => ['nullable', 'string', 'max:30'],
            'origen' => ['nullable', 'string', 'max:30'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        if ($request->is('api/app/*') && $request->user()?->persona_id) {
            $filters['persona_id'] = (int) $request->user()->persona_id;
        }

        return response()->json($this->query->listar($filters));
    }

    public function disponibilidad(Request $request)
    {
        $filters = $request->validate([
            'fecha' => ['nullable', 'date'],
            'sede_id' => ['nullable', 'integer'],
            'servicio_id' => ['nullable', 'integer'],
            'membresia_id' => ['nullable', 'integer'],
        ]);

        $fecha = $filters['fecha'] ?? now()->toDateString();
        $this->service->generarCupos([
            'fecha_desde' => $fecha,
            'dias' => 1,
            'sede_id' => $filters['sede_id'] ?? null,
        ]);

        return response()->json($this->query->disponibilidad([
            ...$filters,
            'fecha' => $fecha,
        ]));
    }

    public function membresiasApp(Request $request)
    {
        $filters = $request->validate([
            'fecha' => ['nullable', 'date'],
        ]);

        $personaId = $request->user()?->persona_id;
        if (!$personaId) {
            return response()->json([]);
        }

        return response()->json($this->query->membresiasParaReservar(
            (int) $personaId,
            $filters['fecha'] ?? now()->toDateString()
        ));
    }

    public function reporteDiario(Request $request)
    {
        $filters = $request->validate([
            'fecha' => ['nullable', 'date'],
            'sede_id' => ['nullable', 'integer'],
            'servicio_id' => ['nullable', 'integer'],
            'membresia_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->query->reporteDiario($filters));
    }

    public function generarCupos(Request $request)
    {
        $filters = $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:60'],
            'sede_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'message' => 'Cupos generados correctamente.',
            'total' => $this->service->generarCupos($filters),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['nullable', 'integer'],
            'socio_membresia_id' => ['nullable', 'integer'],
            'cupo_diario_id' => ['nullable', 'integer'],
            'sede_id' => ['required_without:cupo_diario_id', 'integer'],
            'coach_usuario_id' => ['nullable', 'integer'],
            'servicio_id' => ['nullable', 'integer'],
            'fecha' => ['required_without:cupo_diario_id', 'date'],
            'hora_inicio' => ['required_without:cupo_diario_id', 'date_format:H:i'],
            'hora_fin' => ['required_without:cupo_diario_id', 'date_format:H:i', 'after:hora_inicio'],
            'capacidad' => ['nullable', 'integer', 'min:1'],
            'origen' => ['nullable', 'string', 'max:30'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($request->is('api/app/*')) {
            $data['persona_id'] = $request->user()?->persona_id;
            $data['origen'] = $data['origen'] ?? 'APP';
        }

        return response()->json([
            'message' => 'Reserva creada correctamente.',
            'data' => $this->service->crear($request, $data),
        ], 201);
    }

    public function cancelar(Request $request, int $id)
    {
        $data = $request->validate([
            'motivo' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => 'Reserva cancelada correctamente.',
            'data' => $this->service->cancelar($request, $id, $data['motivo'] ?? null),
        ]);
    }
}
