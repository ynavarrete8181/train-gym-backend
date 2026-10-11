<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\AgendaOperacionServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AgendaOperacionControlador extends Controller
{
    public function __construct(private readonly AgendaOperacionServicio $agenda) {}

    public function catalogos()
    {
        return ApiResponse::exito('Catálogos consultados.', $this->agenda->catalogos());
    }

    public function serviciosDisponibles(Request $request)
    {
        $datos = $request->validate([
            'entrenador_id' => 'required|integer|exists:pgsql.gimnasio.entrenadores,id',
            'sede_id' => 'required|integer|exists:pgsql.institucional.sedes,id_sede',
        ]);

        return ApiResponse::exito(
            'Servicios disponibles consultados.',
            $this->agenda->serviciosDisponibles(
                (int) $datos['entrenador_id'],
                (int) $datos['sede_id'],
            ),
        );
    }

    public function disponibilidad(Request $request)
    {
        $datos = $request->validate([
            'sede_id' => 'required|integer|exists:pgsql.institucional.sedes,id_sede',
            'entrenador_id' => 'required|integer|exists:pgsql.gimnasio.entrenadores,id',
            'servicio_id' => 'required|integer|exists:pgsql.gimnasio.servicios,id',
            'fecha' => 'required|date',
        ]);

        return ApiResponse::exito('Disponibilidad calculada.', $this->agenda->disponibilidad($datos));
    }

    public function reservas(Request $request)
    {
        $p = $this->agenda->listarReservas($request->all());

        return ApiResponse::exito('Reservas consultadas.', $p->items(), [
            'pagina_actual' => $p->currentPage(),
            'por_pagina' => $p->perPage(),
            'total' => $p->total(),
            'ultima_pagina' => $p->lastPage(),
        ]);
    }

    public function excepciones(Request $request)
    {
        $p = $this->agenda->listarExcepciones($request->all());

        return ApiResponse::exito('Excepciones consultadas.', $p->items(), [
            'pagina_actual' => $p->currentPage(),
            'por_pagina' => $p->perPage(),
            'total' => $p->total(),
            'ultima_pagina' => $p->lastPage(),
        ]);
    }

    public function guardarExcepcion(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'entrenador_id' => 'required|integer|exists:pgsql.gimnasio.entrenadores,id',
            'sede_id' => 'required|integer|exists:pgsql.institucional.sedes,id_sede',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => 'nullable|date_format:H:i|after:hora_inicio',
            'tipo' => 'required|string|in:NO_DISPONIBLE,HORARIO_ESPECIAL,CIERRE_SEDE,VACACIONES,CAPACITACION,OTRO',
            'motivo' => 'required|string|max:180',
            'observaciones' => 'nullable|string|max:1000',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito(
            'Excepción guardada correctamente.',
            (array) $this->agenda->guardarExcepcion($datos, $id),
            [],
            $id ? 200 : 201,
        );
    }
}
