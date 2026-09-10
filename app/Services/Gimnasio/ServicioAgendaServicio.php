<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class ServicioAgendaServicio
{
    use RegistraAuditoria;

    public function listarCategorias(array $filtros)
    {
        $query = DB::table('gimnasio.categorias_servicio');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['nombre', 'descripcion']);
        $this->filtrarTexto($query, 'nombre', $filtros['nombre'] ?? null);
        $this->filtrarBooleano($query, 'activo', $filtros['estado'] ?? null);

        return $query->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarCategoria(array $datos, ?int $id = null): object
    {
        $antes = $id ? DB::table('gimnasio.categorias_servicio')->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table('gimnasio.categorias_servicio')->where('id', $id)->update($datos);
            $despues = DB::table('gimnasio.categorias_servicio')->where('id', $id)->first();
            $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.categorias_servicio', $id, $antes, $despues);
            return $despues;
        }

        $datos['created_at'] = now();
        $id = DB::table('gimnasio.categorias_servicio')->insertGetId($datos);
        $despues = DB::table('gimnasio.categorias_servicio')->where('id', $id)->first();
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.categorias_servicio', $id, null, $despues);
        return $despues;
    }

    public function listarServicios(array $filtros)
    {
        $query = DB::table('gimnasio.servicios')
            ->leftJoin('gimnasio.categorias_servicio', 'gimnasio.servicios.categoria_id', '=', 'gimnasio.categorias_servicio.id')
            ->select(
                'gimnasio.servicios.*',
                'gimnasio.categorias_servicio.nombre as categoria_nombre'
            );

        $this->buscar($query, $filtros['busqueda'] ?? null, ['gimnasio.servicios.nombre', 'gimnasio.servicios.descripcion', 'gimnasio.categorias_servicio.nombre']);
        $this->filtrarTexto($query, 'gimnasio.servicios.nombre', $filtros['nombre'] ?? null);
        $this->filtrarTexto($query, 'gimnasio.categorias_servicio.nombre', $filtros['categoria'] ?? null);
        $this->filtrarBooleano($query, 'gimnasio.servicios.activo', $filtros['estado'] ?? null);

        return $query->orderBy('gimnasio.categorias_servicio.nombre')->orderBy('gimnasio.servicios.nombre')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarServicio(array $datos, ?int $id = null): object
    {
        $antes = $id ? DB::table('gimnasio.servicios')->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table('gimnasio.servicios')->where('id', $id)->update($datos);
            $despues = $this->obtenerServicio($id);
            $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.servicios', $id, $antes, $despues);
            return $despues;
        }

        $datos['created_at'] = now();
        $id = DB::table('gimnasio.servicios')->insertGetId($datos);
        $despues = $this->obtenerServicio($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.servicios', $id, null, $despues);
        return $despues;
    }

    public function listarHorarios(array $filtros)
    {
        if (! empty($filtros['detalle'])) {
            return $this->listarHorariosDetalle($filtros);
        }

        $query = DB::table('gimnasio.horario_bloques')
            ->join('gimnasio.servicios', 'gimnasio.horario_bloques.servicio_id', '=', 'gimnasio.servicios.id')
            ->leftJoin('gimnasio.horarios_servicio as horarios', 'gimnasio.horario_bloques.id', '=', 'horarios.horario_bloque_id')
            ->leftJoin('institucional.sedes', 'horarios.sede_id', '=', 'institucional.sedes.id_sede')
            ->select(
                'gimnasio.horario_bloques.*',
                'gimnasio.servicios.nombre as servicio_nombre',
                DB::raw('MIN(horarios.hora_inicio) as hora_inicio'),
                DB::raw('MAX(horarios.hora_fin) as hora_fin'),
                DB::raw('MAX(horarios.capacidad) as capacidad'),
                DB::raw('COUNT(horarios.id) as total_detalles'),
                DB::raw("STRING_AGG(DISTINCT horarios.sede_id::text, ',') as sede_ids_text"),
                DB::raw("STRING_AGG(DISTINCT institucional.sedes.nombre, ', ') as sedes_text"),
                DB::raw("STRING_AGG(DISTINCT horarios.dia_semana, ',') as dias_text")
            );

        $this->buscar($query, $filtros['busqueda'] ?? null, ['gimnasio.horario_bloques.nombre', 'gimnasio.servicios.nombre', 'institucional.sedes.nombre', 'horarios.dia_semana']);
        $this->filtrarTexto($query, 'gimnasio.servicios.nombre', $filtros['servicio'] ?? null);
        $this->filtrarTexto($query, 'institucional.sedes.nombre', $filtros['sede'] ?? null);
        $this->filtrarTexto($query, 'horarios.dia_semana', $filtros['dia'] ?? null);
        $this->filtrarBooleano($query, 'gimnasio.horario_bloques.activo', $filtros['estado'] ?? null);

        $paginador = $query
            ->groupBy('gimnasio.horario_bloques.id', 'gimnasio.servicios.nombre')
            ->orderBy('gimnasio.horario_bloques.nombre')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);

        $ordenDias = ['LUNES' => 1, 'MARTES' => 2, 'MIERCOLES' => 3, 'JUEVES' => 4, 'VIERNES' => 5, 'SABADO' => 6, 'DOMINGO' => 7];
        $paginador->getCollection()->transform(function ($item) use ($ordenDias) {
            $item->sede_ids = collect(explode(',', $item->sede_ids_text ?? ''))->filter()->map(fn ($id) => (int) $id)->values()->all();
            $item->dias_semana = collect(explode(',', $item->dias_text ?? ''))->filter()->sortBy(fn ($dia) => $ordenDias[$dia] ?? 99)->values()->all();
            $item->sede_nombre = $item->sedes_text;
            $item->dia_semana = $item->dias_semana ? implode(', ', $item->dias_semana) : null;
            unset($item->sede_ids_text, $item->dias_text);
            return $item;
        });

        return $paginador;
    }

    private function listarHorariosDetalle(array $filtros)
    {
        $query = DB::table('gimnasio.horarios_servicio')
            ->join('gimnasio.servicios', 'gimnasio.horarios_servicio.servicio_id', '=', 'gimnasio.servicios.id')
            ->join('institucional.sedes', 'gimnasio.horarios_servicio.sede_id', '=', 'institucional.sedes.id_sede')
            ->select(
                'gimnasio.horarios_servicio.*',
                'gimnasio.servicios.nombre as servicio_nombre',
                'institucional.sedes.nombre as sede_nombre'
            );

        $query;
        $this->buscar($query, $filtros['busqueda'] ?? null, ['gimnasio.horarios_servicio.nombre', 'gimnasio.servicios.nombre', 'institucional.sedes.nombre', 'gimnasio.horarios_servicio.dia_semana']);
        $this->filtrarTexto($query, 'gimnasio.servicios.nombre', $filtros['servicio'] ?? null);
        $this->filtrarTexto($query, 'institucional.sedes.nombre', $filtros['sede'] ?? null);
        $this->filtrarTexto($query, 'gimnasio.horarios_servicio.dia_semana', $filtros['dia'] ?? null);
        $this->filtrarBooleano($query, 'gimnasio.horarios_servicio.activo', $filtros['estado'] ?? null);

        return $query->orderByRaw("CASE gimnasio.horarios_servicio.dia_semana WHEN 'LUNES' THEN 1 WHEN 'MARTES' THEN 2 WHEN 'MIERCOLES' THEN 3 WHEN 'JUEVES' THEN 4 WHEN 'VIERNES' THEN 5 WHEN 'SABADO' THEN 6 ELSE 7 END")
            ->orderBy('gimnasio.horarios_servicio.hora_inicio')
            ->orderBy('gimnasio.horarios_servicio.nombre')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarHorarioBloque(array $datos, array $sedeIds, array $diasSemana, ?int $id = null): object
    {
        return DB::transaction(function () use ($datos, $sedeIds, $diasSemana, $id) {
            $bloque = [
                'nombre' => $datos['nombre'] ?? null,
                'servicio_id' => $datos['servicio_id'],
                'activo' => $datos['activo'] ?? true,
                'updated_at' => now(),
            ];

            if ($id) {
                DB::table('gimnasio.horario_bloques')->where('id', $id)->update($bloque);
                $bloqueId = $id;
            } else {
                $bloque['created_at'] = now();
                $bloqueId = DB::table('gimnasio.horario_bloques')->insertGetId($bloque);
            }

            $combinacionesActivas = [];
            foreach (array_values(array_unique($sedeIds)) as $sedeId) {
                foreach (array_values(array_unique($diasSemana)) as $diaSemana) {
                    $combinacionesActivas[] = "{$sedeId}|{$diaSemana}";
                    $detalle = DB::table('gimnasio.horarios_servicio')
                        ->where('horario_bloque_id', $bloqueId)
                        ->where('sede_id', (int) $sedeId)
                        ->where('dia_semana', $diaSemana)
                        ->first();

                    $detalleDatos = [
                        'horario_bloque_id' => $bloqueId,
                        'nombre' => $datos['nombre'] ?? null,
                        'servicio_id' => $datos['servicio_id'],
                        'sede_id' => (int) $sedeId,
                        'entrenador_id' => null,
                        'dia_semana' => $diaSemana,
                        'hora_inicio' => $datos['hora_inicio'],
                        'hora_fin' => $datos['hora_fin'],
                        'capacidad' => $datos['capacidad'],
                        'activo' => $datos['activo'] ?? true,
                    ];

                    $detalle
                        ? $this->guardarHorario($detalleDatos, $detalle->id)
                        : $this->guardarHorario($detalleDatos);
                }
            }

            DB::table('gimnasio.horarios_servicio')
                ->where('horario_bloque_id', $bloqueId)
                ->get()
                ->each(function ($detalle) use ($combinacionesActivas): void {
                    if (! in_array("{$detalle->sede_id}|{$detalle->dia_semana}", $combinacionesActivas, true)) {
                        DB::table('gimnasio.horarios_servicio')
                            ->where('id', $detalle->id)
                            ->update(['activo' => false, 'updated_at' => now()]);
                    }
                });

            $bloqueDespues = $this->obtenerHorarioBloque($bloqueId);
            $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'gimnasio.horario_bloques', $bloqueId, null, $bloqueDespues);
            return $bloqueDespues;
        });
    }

    public function listarDetalleHorarioBloque(int $id): array
    {
        $bloque = $this->obtenerHorarioBloque($id);
        $detalles = DB::table('gimnasio.horarios_servicio')
            ->join('institucional.sedes', 'gimnasio.horarios_servicio.sede_id', '=', 'institucional.sedes.id_sede')
            ->where('gimnasio.horarios_servicio.horario_bloque_id', $id)
            ->orderByRaw("CASE gimnasio.horarios_servicio.dia_semana WHEN 'LUNES' THEN 1 WHEN 'MARTES' THEN 2 WHEN 'MIERCOLES' THEN 3 WHEN 'JUEVES' THEN 4 WHEN 'VIERNES' THEN 5 WHEN 'SABADO' THEN 6 ELSE 7 END")
            ->orderBy('gimnasio.horarios_servicio.hora_inicio')
            ->orderBy('institucional.sedes.nombre')
            ->get(['gimnasio.horarios_servicio.*', 'institucional.sedes.nombre as sede_nombre'])
            ->all();

        return [
            'bloque' => $bloque,
            'detalles' => $detalles,
        ];
    }

    public function desactivarHorarioBloque(int $id): object
    {
        DB::transaction(function () use ($id): void {
            DB::table('gimnasio.horario_bloques')
                ->where('id', $id)
                ->update(['activo' => false, 'updated_at' => now()]);

            DB::table('gimnasio.horarios_servicio')
                ->where('horario_bloque_id', $id)
                ->update(['activo' => false, 'updated_at' => now()]);
        });

        $bloque = $this->obtenerHorarioBloque($id);
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.horario_bloques', $id, null, $bloque, 'Horario desactivado.');
        return $bloque;
    }

    public function eliminarHorarioBloque(int $id): object
    {
        return DB::transaction(function () use ($id): object {
            $detalleIds = DB::table('gimnasio.horarios_servicio')
                ->where('horario_bloque_id', $id)
                ->pluck('id');

            $tieneReservas = DB::table('gimnasio.reservas_dia')
                ->whereIn('horario_id', $detalleIds)
                ->exists();

            if (! $tieneReservas) {
                DB::table('gimnasio.horarios_servicio')->where('horario_bloque_id', $id)->delete();
                DB::table('gimnasio.horario_bloques')->where('id', $id)->delete();

                $resultado = (object) [
                    'id' => $id,
                    'eliminado' => true,
                    'tipo_eliminacion' => 'fisica',
                ];
                $this->auditar('gimnasio', 'ELIMINAR', 'gimnasio.horario_bloques', $id, null, null, 'Horario eliminado definitivamente.');
                return $resultado;
            }

            DB::table('gimnasio.horario_bloques')
                ->where('id', $id)
                ->update(['activo' => false, 'updated_at' => now()]);

            DB::table('gimnasio.horarios_servicio')
                ->where('horario_bloque_id', $id)
                ->update(['activo' => false, 'updated_at' => now()]);

            $resultado = (object) [
                'id' => $id,
                'eliminado' => true,
                'tipo_eliminacion' => 'logica',
            ];
            $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.horario_bloques', $id, null, $resultado, 'Horario desactivado por tener reservas asociadas.');
            return $resultado;
        });
    }

    private function guardarHorario(array $datos, ?int $id = null): object
    {
        $datos['updated_at'] = now();
        if ($id) {
            DB::table('gimnasio.horarios_servicio')->where('id', $id)->update($datos);
            return $this->obtenerHorario($id);
        }

        $datos['created_at'] = now();
        $id = DB::table('gimnasio.horarios_servicio')->insertGetId($datos);
        return $this->obtenerHorario($id);
    }

    public function listarReservasDia(array $filtros)
    {
        $query = DB::table('gimnasio.reservas_dia')
            ->join('gimnasio.deportistas', 'gimnasio.reservas_dia.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->join('gimnasio.servicios', 'gimnasio.reservas_dia.servicio_id', '=', 'gimnasio.servicios.id')
            ->leftJoin('institucional.sedes', 'gimnasio.reservas_dia.sede_id', '=', 'institucional.sedes.id_sede')
            ->select(
                'gimnasio.reservas_dia.*',
                'clientes.name as cliente_nombre',
                'gimnasio.deportistas.codigo_deportista',
                'gimnasio.servicios.nombre as servicio_nombre',
                'institucional.sedes.nombre as sede_nombre'
            );

        $this->buscar($query, $filtros['busqueda'] ?? null, ['clientes.name', 'gimnasio.deportistas.codigo_deportista', 'gimnasio.servicios.nombre', 'institucional.sedes.nombre', 'gimnasio.reservas_dia.estado']);
        $this->filtrarTexto($query, 'clientes.name', $filtros['cliente'] ?? null);
        $this->filtrarTexto($query, 'gimnasio.servicios.nombre', $filtros['servicio'] ?? null);
        $this->filtrarTexto($query, 'gimnasio.reservas_dia.estado', $filtros['estado'] ?? null);

        if (! empty($filtros['fecha'])) {
            $query->whereDate('gimnasio.reservas_dia.fecha', $filtros['fecha']);
        }

        return $query->orderByDesc('gimnasio.reservas_dia.fecha')->orderBy('gimnasio.reservas_dia.hora_inicio')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarReservaDia(array $datos, ?int $id = null): object
    {
        $antes = $id ? DB::table('gimnasio.reservas_dia')->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table('gimnasio.reservas_dia')->where('id', $id)->update($datos);
            $despues = $this->obtenerReservaDia($id);
            $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.reservas_dia', $id, $antes, $despues);
            return $despues;
        }

        $datos['created_at'] = now();
        $id = DB::table('gimnasio.reservas_dia')->insertGetId($datos);
        $despues = $this->obtenerReservaDia($id);
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.reservas_dia', $id, null, $despues);
        return $despues;
    }

    public function opcionesFiltro(): array
    {
        return [
            'categorias' => DB::table('gimnasio.categorias_servicio')->where('activo', true)->orderBy('nombre')->get(),
            'servicios' => DB::table('gimnasio.servicios')->where('activo', true)->orderBy('nombre')->get(),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
        ];
    }

    private function obtenerServicio(int $id): object
    {
        return DB::table('gimnasio.servicios')
            ->leftJoin('gimnasio.categorias_servicio', 'gimnasio.servicios.categoria_id', '=', 'gimnasio.categorias_servicio.id')
            ->select('gimnasio.servicios.*', 'gimnasio.categorias_servicio.nombre as categoria_nombre')
            ->where('gimnasio.servicios.id', $id)
            ->first();
    }

    private function obtenerHorario(int $id): object
    {
        return DB::table('gimnasio.horarios_servicio')
            ->join('gimnasio.servicios', 'gimnasio.horarios_servicio.servicio_id', '=', 'gimnasio.servicios.id')
            ->join('institucional.sedes', 'gimnasio.horarios_servicio.sede_id', '=', 'institucional.sedes.id_sede')
            ->select('gimnasio.horarios_servicio.*', 'gimnasio.servicios.nombre as servicio_nombre', 'institucional.sedes.nombre as sede_nombre')
            ->where('gimnasio.horarios_servicio.id', $id)
            ->first();
    }

    private function obtenerHorarioBloque(int $id): object
    {
        $bloque = DB::table('gimnasio.horario_bloques')
            ->join('gimnasio.servicios', 'gimnasio.horario_bloques.servicio_id', '=', 'gimnasio.servicios.id')
            ->select('gimnasio.horario_bloques.*', 'gimnasio.servicios.nombre as servicio_nombre')
            ->where('gimnasio.horario_bloques.id', $id)
            ->first();

        $detalles = DB::table('gimnasio.horarios_servicio')
            ->join('institucional.sedes', 'gimnasio.horarios_servicio.sede_id', '=', 'institucional.sedes.id_sede')
            ->where('gimnasio.horarios_servicio.horario_bloque_id', $id)
            ->where('gimnasio.horarios_servicio.activo', true)
            ->get(['gimnasio.horarios_servicio.*', 'institucional.sedes.nombre as sede_nombre']);

        $ordenDias = ['LUNES' => 1, 'MARTES' => 2, 'MIERCOLES' => 3, 'JUEVES' => 4, 'VIERNES' => 5, 'SABADO' => 6, 'DOMINGO' => 7];
        $bloque->sede_ids = $detalles->pluck('sede_id')->unique()->values()->all();
        $bloque->dias_semana = $detalles->pluck('dia_semana')->unique()->sortBy(fn ($dia) => $ordenDias[$dia] ?? 99)->values()->all();
        $bloque->sede_nombre = $detalles->pluck('sede_nombre')->unique()->implode(', ');
        $bloque->dia_semana = implode(', ', $bloque->dias_semana);
        $bloque->hora_inicio = optional($detalles->first())->hora_inicio;
        $bloque->hora_fin = optional($detalles->first())->hora_fin;
        $bloque->capacidad = optional($detalles->first())->capacidad;
        $bloque->total_detalles = $detalles->count();

        return $bloque;
    }

    private function obtenerReservaDia(int $id): object
    {
        return DB::table('gimnasio.reservas_dia')
            ->join('gimnasio.deportistas', 'gimnasio.reservas_dia.cliente_id', '=', 'gimnasio.deportistas.id')
            ->join('seguridad.users as clientes', 'gimnasio.deportistas.usuario_id', '=', 'clientes.id')
            ->join('gimnasio.servicios', 'gimnasio.reservas_dia.servicio_id', '=', 'gimnasio.servicios.id')
            ->leftJoin('institucional.sedes', 'gimnasio.reservas_dia.sede_id', '=', 'institucional.sedes.id_sede')
            ->select('gimnasio.reservas_dia.*', 'clientes.name as cliente_nombre', 'gimnasio.deportistas.codigo_deportista', 'gimnasio.servicios.nombre as servicio_nombre', 'institucional.sedes.nombre as sede_nombre')
            ->where('gimnasio.reservas_dia.id', $id)
            ->first();
    }

    private function buscar($query, ?string $busqueda, array $columnas): void
    {
        if (empty($busqueda)) {
            return;
        }

        $texto = mb_strtolower($busqueda);
        $query->where(function ($q) use ($columnas, $texto): void {
            foreach ($columnas as $columna) {
                $q->orWhereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
            }
        });
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) {
            return;
        }

        $valores = is_array($valor) ? array_filter($valor) : [$valor];
        if (empty($valores)) {
            return;
        }

        is_array($valor)
            ? $query->whereIn($columna, $valores)
            : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function filtrarBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $normalizado = is_array($valor) ? $valor : [$valor];
        $booleanos = collect($normalizado)->map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))->filter(fn ($item) => $item !== null)->values();

        if ($booleanos->isNotEmpty()) {
            $query->whereIn($columna, $booleanos->all());
        }
    }
}
