<?php

namespace App\Http\Controllers\Api\Gimnasio;

use App\Http\Controllers\Controller;
use App\Services\Gimnasio\ServicioAgendaServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServicioAgendaControlador extends Controller
{
    public function __construct(private readonly ServicioAgendaServicio $servicioAgenda)
    {
    }

    public function categorias(Request $request)
    {
        $resultado = $this->servicioAgenda->listarCategorias($request->all());
        return $this->respuestaPaginada('Categorías consultadas.', $resultado, $this->metaFiltros());
    }

    public function guardarCategoria(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150', Rule::unique('gimnasio.categorias_servicio', 'nombre')->ignore($id)],
            'descripcion' => 'nullable|string',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito('Categoría guardada correctamente.', (array) $this->servicioAgenda->guardarCategoria($datos, $id), [], $id ? 200 : 201);
    }

    public function servicios(Request $request)
    {
        $resultado = $this->servicioAgenda->listarServicios($request->all());
        return $this->respuestaPaginada('Servicios consultados.', $resultado, $this->metaFiltros());
    }

    public function guardarServicio(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'categoria_id' => 'required|exists:pgsql.gimnasio.categorias_servicio,id',
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'duracion_minutos' => 'required|integer|min:1|max:1440',
            'capacidad_base' => 'required|integer|min:1|max:500',
            'requiere_reserva' => 'boolean',
            'activo' => 'boolean',
        ]);

        return ApiResponse::exito('Servicio guardado correctamente.', (array) $this->servicioAgenda->guardarServicio($datos, $id), [], $id ? 200 : 201);
    }

    public function horarios(Request $request)
    {
        $resultado = $this->servicioAgenda->listarHorarios($request->all());
        return $this->respuestaPaginada('Horarios consultados.', $resultado, $this->metaFiltros());
    }

    public function guardarHorario(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:150',
            'servicio_id' => 'required|exists:pgsql.gimnasio.servicios,id',
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'sede_ids' => 'nullable|array|min:1|max:1',
            'sede_ids.*' => 'required|distinct|exists:pgsql.institucional.sedes,id_sede',
            'dia_semana' => 'nullable|string|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'dias_semana' => 'nullable|array|min:1',
            'dias_semana.*' => 'required|distinct|string|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'capacidad' => 'required|integer|min:1|max:500',
            'activo' => 'boolean',
        ]);

        $servicio = DB::table('gimnasio.servicios')->where('id', $datos['servicio_id'])->first();
        if ($servicio && (int) $datos['capacidad'] > (int) $servicio->capacidad_base) {
            throw ValidationException::withMessages([
                'capacidad' => "La capacidad del horario no puede superar el cupo base del servicio ({$servicio->capacidad_base}).",
            ]);
        }

        $sedeIds = $datos['sede_ids'] ?? (isset($datos['sede_id']) ? [$datos['sede_id']] : []);
        $diasSemana = $datos['dias_semana'] ?? (isset($datos['dia_semana']) ? [$datos['dia_semana']] : []);

        if (empty($sedeIds) || empty($diasSemana)) {
            return ApiResponse::error('Selecciona una sede y al menos un día para guardar el horario.', [], 422);
        }

        if (count(array_unique(array_map('intval', $sedeIds))) !== 1) {
            throw ValidationException::withMessages([
                'sede_ids' => 'Cada bloque de horario debe pertenecer a una sola sede. Crea otro bloque para una sede diferente.',
            ]);
        }

        unset($datos['sede_id'], $datos['sede_ids'], $datos['dia_semana'], $datos['dias_semana']);
        $horario = $this->servicioAgenda->guardarHorarioBloque($datos, $sedeIds, $diasSemana, $id);

        return ApiResponse::exito('Horario guardado correctamente.', (array) $horario, [], $id ? 200 : 201);
    }

    public function detalleHorario(int $id)
    {
        return ApiResponse::exito('Detalle del horario consultado.', $this->servicioAgenda->listarDetalleHorarioBloque($id));
    }

    public function desactivarHorario(int $id)
    {
        $horario = $this->servicioAgenda->desactivarHorarioBloque($id);
        return ApiResponse::exito('Horario desactivado correctamente.', (array) $horario);
    }

    public function eliminarHorario(int $id)
    {
        $horario = $this->servicioAgenda->eliminarHorarioBloque($id);
        return ApiResponse::exito('Horario eliminado correctamente.', (array) $horario);
    }

    public function reservasDia(Request $request)
    {
        $resultado = $this->servicioAgenda->listarReservasDia($request->all());
        return $this->respuestaPaginada('Reservas consultadas.', $resultado, $this->metaFiltros());
    }

    public function guardarReservaDia(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'cliente_id' => 'required|exists:pgsql.gimnasio.deportistas,id',
            'servicio_id' => 'required|exists:pgsql.gimnasio.servicios,id',
            'horario_id' => 'nullable|exists:pgsql.gimnasio.horarios_servicio,id',
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'fecha' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'estado' => 'required|string|in:RESERVADA,ASISTIO,CANCELADA,NO_ASISTIO',
            'observaciones' => 'nullable|string',
        ]);

        return ApiResponse::exito('Reserva guardada correctamente.', (array) $this->servicioAgenda->guardarReservaDia($datos, $id), [], $id ? 200 : 201);
    }

    private function respuestaPaginada(string $mensaje, $paginador, array $metaExtra = [])
    {
        return ApiResponse::exito($mensaje, $paginador->items(), array_merge([
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
        ], $metaExtra));
    }

    private function metaFiltros(): array
    {
        return [
            'opciones_filtro' => [
                'nombre' => DB::table('gimnasio.categorias_servicio')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
                'categoria' => DB::table('gimnasio.categorias_servicio')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
                'servicio' => DB::table('gimnasio.servicios')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
                'sede' => DB::table('institucional.sedes')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
                'cliente' => DB::table('gimnasio.deportistas')->join('seguridad.users', 'gimnasio.deportistas.usuario_id', '=', 'seguridad.users.id')->distinct()->orderBy('seguridad.users.name')->pluck('seguridad.users.name')->values(),
            ],
            'catalogos' => $this->servicioAgenda->opcionesFiltro(),
        ];
    }

}
