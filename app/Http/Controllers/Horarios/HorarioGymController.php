<?php

namespace App\Http\Controllers\Horarios;

use App\Http\Controllers\Controller;
use App\Services\Horarios\HorarioService;
use Illuminate\Http\Request;

class HorarioGymController extends Controller
{
    public function __construct(private HorarioService $horarioService)
    {
    }

    public function index()
    {
        return response()->json($this->horarioService->all());
    }

    public function show(int $id)
    {
        $horario = $this->horarioService->find($id);

        if (!$horario) {
            return response()->json(['message' => 'Horario no encontrado'], 404);
        }

        return response()->json($horario);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sede_id' => ['nullable', 'integer'],
            'tipo_servicio_id' => ['required', 'integer'],
            'hora_apertura' => ['required'],
            'hora_cierre' => ['required'],
            'capacidad_maxima' => ['required', 'integer', 'min:1'],
            'tiempo_turno_min' => ['required', 'integer', 'min:1'],
            'tipo_usuario' => ['required', 'integer', 'min:1'],
            'dias_laborables' => ['required', 'array', 'min:1'],
            'dias_laborables.*' => ['integer', 'between:1,7'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $horario = $this->horarioService->create($data, $request);

        return response()->json([
            'message' => 'Horario creado correctamente',
            'data' => $horario,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'sede_id' => ['nullable', 'integer'],
            'tipo_servicio_id' => ['required', 'integer'],
            'hora_apertura' => ['required'],
            'hora_cierre' => ['required'],
            'capacidad_maxima' => ['required', 'integer', 'min:1'],
            'tiempo_turno_min' => ['required', 'integer', 'min:1'],
            'tipo_usuario' => ['required', 'integer', 'min:1'],
            'dias_laborables' => ['required', 'array', 'min:1'],
            'dias_laborables.*' => ['integer', 'between:1,7'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $horario = $this->horarioService->update($id, $data, $request);

        if (!$horario) {
            return response()->json(['message' => 'Horario no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Horario actualizado correctamente',
            'data' => $horario,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $horario = $this->horarioService->delete($id, $request);

        if (!$horario) {
            return response()->json(['message' => 'Horario no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Horario desactivado correctamente',
            'data' => $horario,
        ]);
    }
}
