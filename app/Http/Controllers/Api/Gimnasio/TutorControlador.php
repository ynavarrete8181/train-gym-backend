<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\TutorServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TutorControlador extends Controller
{
    public function __construct(private readonly TutorServicio $tutores)
    {
    }

    public function buscar(Request $request)
    {
        $datos = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'excluir_persona_id' => 'nullable|integer',
        ]);

        return ApiResponse::exito('Tutores consultados.', $this->tutores->buscar(
            $datos['q'],
            isset($datos['excluir_persona_id']) ? (int) $datos['excluir_persona_id'] : null,
        ));
    }

    public function store(Request $request)
    {
        return ApiResponse::exito('Tutor registrado correctamente.', $this->tutores->crear($this->validar($request)), [], 201);
    }

    public function show(int $id)
    {
        $tutor = $this->tutores->obtener($id);

        return $tutor
            ? ApiResponse::exito('Tutor consultado.', $tutor)
            : response()->json(['mensaje' => 'Tutor no encontrado'], 404);
    }

    public function update(Request $request, int $id)
    {
        return ApiResponse::exito('Tutor actualizado correctamente.', $this->tutores->actualizar($id, $this->validar($request)));
    }

    public function quitarDeCliente(Request $request, int $id)
    {
        $datos = $request->validate(['motivo' => 'nullable|string|max:250']);

        return ApiResponse::exito('Tutor quitado. El vínculo queda en el historial.', $this->tutores->quitarDeCliente($id, $datos['motivo'] ?? null));
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'persona' => 'required|array',
            'persona.tipo_identificacion' => 'nullable|string|max:30',
            'persona.identificacion' => 'required|string|max:50',
            'persona.nombres' => 'required|string|max:150',
            'persona.apellidos' => 'nullable|string|max:150',
            'persona.fecha_nacimiento' => 'nullable|date',
            'persona.genero' => 'nullable|string|max:30',
            'persona.telefono' => 'nullable|string|max:30',
            'persona.email' => 'nullable|email|max:190',
            'persona.direccion' => 'nullable|string',
            'persona.provincia_codigo' => ['nullable', 'regex:/^[0-9]{2}$/', 'exists:pgsql.geografia.provincias,codigo_dpa'],
            'persona.canton_codigo' => ['nullable', 'regex:/^[0-9]{4}$/', 'exists:pgsql.geografia.cantones,codigo_dpa'],
            'persona.parroquia_codigo' => ['nullable', 'regex:/^[0-9]{6}$/', 'exists:pgsql.geografia.parroquias,codigo_dpa'],
            'ubicacion' => 'nullable|array',
            'ubicacion.latitud' => 'required_with:ubicacion.longitud|nullable|numeric|between:-5.1,2',
            'ubicacion.longitud' => 'required_with:ubicacion.latitud|nullable|numeric|between:-92.1,-74',
            'ubicacion.referencia' => 'nullable|string|max:240',
            'estado' => 'nullable|string|in:PROSPECTO,ACTIVO,INACTIVO,SUSPENDIDO',
            'tipo_cliente' => 'nullable|string|in:TUTOR,DEPORTISTA_TUTOR',
        ]);
    }
}
