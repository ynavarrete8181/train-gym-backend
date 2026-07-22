<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Queries\Asistencia\AsistenciaQuery;
use App\Services\Asistencia\AsistenciaService;
use Illuminate\Http\Request;

class AsistenciaController extends Controller
{
    public function __construct(
        private AsistenciaQuery $query,
        private AsistenciaService $service
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'persona_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'buscar' => ['nullable', 'string', 'max:150'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
        ]);

        return response()->json($this->query->listar($filters));
    }

    public function registrar(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'sede_id' => ['required', 'integer'],
            'reserva_id' => ['nullable', 'integer'],
            'socio_membresia_id' => ['nullable', 'integer'],
            'coach_id' => ['nullable', 'integer'],
            'staff_cliente_asignacion_id' => ['nullable', 'integer'],
            'turno_recurrente_id' => ['nullable', 'integer'],
            'fecha_hora' => ['nullable', 'date'],
            'tipo' => ['nullable', 'string', 'max:20'],
            'metodo' => ['nullable', 'string', 'max:30'],
            'origen' => ['nullable', 'string', 'max:30'],
            'motivo' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        return response()->json([
            'message' => 'Asistencia registrada correctamente.',
            'data' => $this->service->registrar($request, $data),
        ], 201);
    }
}
