<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\AgendaConfiguracionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgendaConfiguracionControlador extends Controller
{
    public function __construct(
        private readonly AgendaConfiguracionServicio $agenda,
    ) {
    }

    public function jornadas(Request $request)
    {
        $resultado = $this->agenda->listarJornadas($request->all());

        return $this->respuestaPaginada('Jornadas consultadas.', $resultado);
    }

    public function guardarJornada(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('gimnasio.jornadas', 'nombre')->ignore($id)],
            'descripcion' => 'nullable|string|max:1000',
            'dias_semana' => 'required|array|min:1',
            'dias_semana.*' => 'required|distinct|string|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito(
            'Jornada guardada correctamente.',
            (array) $this->agenda->guardarJornada($datos, $id),
            [],
            $id ? 200 : 201,
        );
    }

    public function recesos(Request $request)
    {
        $resultado = $this->agenda->listarRecesos($request->all());

        return $this->respuestaPaginada('Recesos consultados.', $resultado);
    }

    public function guardarReceso(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('gimnasio.recesos', 'nombre')->ignore($id)],
            'tipo' => 'required|string|in:DESAYUNO,ALMUERZO,MERIENDA,PAUSA,OTRO',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'descripcion' => 'nullable|string|max:1000',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito(
            'Receso guardado correctamente.',
            (array) $this->agenda->guardarReceso($datos, $id),
            [],
            $id ? 200 : 201,
        );
    }

    public function asignaciones(Request $request)
    {
        $resultado = $this->agenda->listarAsignaciones($request->all());

        return $this->respuestaPaginada('Asignaciones de horario consultadas.', $resultado);
    }

    public function catalogosAsignacion(Request $request)
    {
        return ApiResponse::exito(
            'Catálogos de asignación consultados.',
            $this->agenda->catalogosAsignacion(
                $request->filled('entrenador_id') ? (int) $request->input('entrenador_id') : null,
            ),
        );
    }

    public function buscarEntrenadores(Request $request)
    {
        $busqueda = $request->input('q');
        $entrenadorId = $request->filled('entrenador_id') ? (int) $request->input('entrenador_id') : null;

        return ApiResponse::exito(
            'Entrenadores consultados.',
            $this->agenda->buscarEntrenadores($busqueda, $entrenadorId, 20),
        );
    }

    public function guardarAsignacion(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'entrenador_id' => 'required|exists:pgsql.gimnasio.entrenadores,id',
            'sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'jornada_id' => 'required|exists:pgsql.gimnasio.jornadas,id',
            'receso_id' => 'nullable|exists:pgsql.gimnasio.recesos,id',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'activo' => 'boolean',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        return ApiResponse::exito(
            'Asignación de horario guardada correctamente.',
            (array) $this->agenda->guardarAsignacion($datos, $id),
            [],
            $id ? 200 : 201,
        );
    }

    private function respuestaPaginada(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
        ]);
    }
}
