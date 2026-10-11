<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaOperacionServicio
{
    use RegistraAuditoria;

    private const DIAS = [
        1 => 'LUNES',
        2 => 'MARTES',
        3 => 'MIERCOLES',
        4 => 'JUEVES',
        5 => 'VIERNES',
        6 => 'SABADO',
        7 => 'DOMINGO',
    ];

    private const ESTADOS_RESERVA_ACTIVA = ['RESERVADA', 'CONFIRMADA', 'EN_CURSO'];

    public function catalogos(): array
    {
        return [
            'sedes' => DB::table('institucional.sedes')
                ->where('activo', true)
                ->where('permite_reservas', true)
                ->orderBy('nombre')
                ->get(['id_sede as id', 'nombre']),
            'servicios' => DB::table('gimnasio.servicios')
                ->where('activo', true)
                ->where('requiere_reserva', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'duracion_minutos', 'capacidad_base']),
        ];
    }

    public function serviciosDisponibles(int $entrenadorId, int $sedeId): array
    {
        $tieneAsignacion = DB::table('gimnasio.asignaciones_horario_entrenador')
            ->where('entrenador_id', $entrenadorId)
            ->where('sede_id', $sedeId)
            ->where('activo', true)
            ->exists();

        if (! $tieneAsignacion) {
            return [];
        }

        return DB::table('gimnasio.entrenador_servicios as es')
            ->join('gimnasio.servicios as s', 's.id', '=', 'es.servicio_id')
            ->where('es.entrenador_id', $entrenadorId)
            ->where('es.activo', true)
            ->where('s.activo', true)
            ->where('s.requiere_reserva', true)
            ->orderBy('s.nombre')
            ->get([
                's.id',
                's.nombre',
                's.duracion_minutos',
                's.capacidad_base',
            ])
            ->all();
    }

    public function disponibilidad(array $filtros): array
    {
        $fecha = Carbon::parse($filtros['fecha'])->startOfDay();
        $dia = self::DIAS[$fecha->isoWeekday()] ?? null;
        $entrenadorId = (int) $filtros['entrenador_id'];
        $sedeId = (int) $filtros['sede_id'];
        $servicioId = (int) $filtros['servicio_id'];

        $servicio = DB::table('gimnasio.servicios as s')
            ->join('gimnasio.entrenador_servicios as es', function ($join) use ($entrenadorId): void {
                $join->on('es.servicio_id', '=', 's.id')
                    ->where('es.entrenador_id', $entrenadorId)
                    ->where('es.activo', true);
            })
            ->where('s.id', $servicioId)
            ->where('s.activo', true)
            ->where('s.requiere_reserva', true)
            ->first(['s.id', 's.nombre', 's.duracion_minutos', 's.capacidad_base']);

        if (! $servicio) {
            throw ValidationException::withMessages([
                'servicio_id' => 'El entrenador no tiene habilitado este servicio.',
            ]);
        }

        $asignaciones = DB::table('gimnasio.asignaciones_horario_entrenador as a')
            ->join('gimnasio.jornadas as j', 'j.id', '=', 'a.jornada_id')
            ->leftJoin('gimnasio.recesos as r', 'r.id', '=', 'a.receso_id')
            ->where('a.entrenador_id', $entrenadorId)
            ->where('a.sede_id', $sedeId)
            ->where('a.activo', true)
            ->where('j.activo', true)
            ->whereDate('a.fecha_inicio', '<=', $fecha->toDateString())
            ->where(function ($q) use ($fecha): void {
                $q->whereNull('a.fecha_fin')
                    ->orWhereDate('a.fecha_fin', '>=', $fecha->toDateString());
            })
            ->select(
                'a.id',
                'a.jornada_id',
                'j.nombre as jornada_nombre',
                'r.id as receso_id',
                'r.nombre as receso_nombre',
                'r.hora_inicio as receso_hora_inicio',
                'r.hora_fin as receso_hora_fin'
            )
            ->get();

        $bloques = collect();

        foreach ($asignaciones as $asignacion) {
            $detalle = DB::table('gimnasio.jornada_detalles')
                ->where('jornada_id', $asignacion->jornada_id)
                ->where('dia_semana', $dia)
                ->where('activo', true)
                ->first();

            if (! $detalle) {
                continue;
            }

            $inicio = Carbon::parse($fecha->toDateString() . ' ' . $detalle->hora_inicio);
            $finJornada = Carbon::parse($fecha->toDateString() . ' ' . $detalle->hora_fin);
            $duracion = max(5, (int) $servicio->duracion_minutos);
            $capacidad = max(1, (int) $servicio->capacidad_base);

            while ($inicio->copy()->addMinutes($duracion)->lte($finJornada)) {
                $fin = $inicio->copy()->addMinutes($duracion);

                $estado = 'DISPONIBLE';
                $motivo = null;

                if (
                    $asignacion->receso_id
                    && $this->solapa(
                        $inicio->format('H:i:s'),
                        $fin->format('H:i:s'),
                        $asignacion->receso_hora_inicio,
                        $asignacion->receso_hora_fin
                    )
                ) {
                    $estado = 'RECESO';
                    $motivo = $asignacion->receso_nombre;
                }

                $excepcion = $this->buscarExcepcion(
                    $entrenadorId,
                    $sedeId,
                    $fecha->toDateString(),
                    $inicio->format('H:i:s'),
                    $fin->format('H:i:s'),
                );

                if ($excepcion) {
                    $estado = 'EXCEPCION';
                    $motivo = $excepcion->motivo;
                }

                $reservas = DB::table('gimnasio.reservas_dia')
                    ->where('entrenador_id', $entrenadorId)
                    ->where('sede_id', $sedeId)
                    ->whereDate('fecha', $fecha->toDateString())
                    ->whereIn('estado', self::ESTADOS_RESERVA_ACTIVA)
                    ->where('hora_inicio', '<', $fin->format('H:i:s'))
                    ->where('hora_fin', '>', $inicio->format('H:i:s'))
                    ->count();

                if ($estado === 'DISPONIBLE' && $reservas >= $capacidad) {
                    $estado = 'RESERVADO';
                    $motivo = 'Cupo completo';
                }

                $bloques->push([
                    'asignacion_id' => $asignacion->id,
                    'jornada' => $asignacion->jornada_nombre,
                    'fecha' => $fecha->toDateString(),
                    'hora_inicio' => $inicio->format('H:i'),
                    'hora_fin' => $fin->format('H:i'),
                    'estado' => $estado,
                    'motivo' => $motivo,
                    'reservas' => $reservas,
                    'capacidad' => $capacidad,
                    'cupos_disponibles' => max(0, $capacidad - $reservas),
                ]);

                $inicio = $fin;
            }
        }

        return [
            'fecha' => $fecha->toDateString(),
            'dia' => $dia,
            'servicio' => $servicio,
            'slots' => $bloques
                ->unique(fn ($slot) => $slot['hora_inicio'] . '-' . $slot['hora_fin'])
                ->sortBy('hora_inicio')
                ->values()
                ->all(),
        ];
    }

    public function listarReservas(array $filtros)
    {
        $query = DB::table('gimnasio.reservas_dia as r')
            ->join('gimnasio.deportistas as d', 'd.id', '=', 'r.cliente_id')
            ->join('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->join('gimnasio.servicios as s', 's.id', '=', 'r.servicio_id')
            ->leftJoin('gimnasio.entrenadores as e', 'e.id', '=', 'r.entrenador_id')
            ->leftJoin('seguridad.users as ue', 'ue.id', '=', 'e.usuario_id')
            ->leftJoin('institucional.sedes as sd', 'sd.id_sede', '=', 'r.sede_id')
            ->select(
                'r.*',
                'u.name as cliente_nombre',
                'd.codigo_deportista',
                's.nombre as servicio_nombre',
                'ue.name as entrenador_nombre',
                'sd.nombre as sede_nombre'
            );

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower(trim($filtros['busqueda'])) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw("LOWER(COALESCE(u.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(d.codigo_deportista, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(s.nombre, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(ue.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(sd.nombre, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['fecha'])) {
            $query->whereDate('r.fecha', $filtros['fecha']);
        }

        if (! empty($filtros['sede_id'])) {
            $query->where('r.sede_id', (int) $filtros['sede_id']);
        }

        if (! empty($filtros['entrenador_id'])) {
            $query->where('r.entrenador_id', (int) $filtros['entrenador_id']);
        }

        if (! empty($filtros['estado'])) {
            $query->where('r.estado', $filtros['estado']);
        }

        return $query
            ->orderByDesc('r.fecha')
            ->orderBy('r.hora_inicio')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function listarExcepciones(array $filtros)
    {
        $query = DB::table('gimnasio.excepciones_horario as x')
            ->join('gimnasio.entrenadores as e', 'e.id', '=', 'x.entrenador_id')
            ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'x.sede_id')
            ->select(
                'x.*',
                'u.name as entrenador_nombre',
                'e.especialidad as entrenador_especialidad',
                's.nombre as sede_nombre'
            );

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower(trim($filtros['busqueda'])) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw("LOWER(COALESCE(u.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(s.nombre, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(x.motivo, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['entrenador_id'])) {
            $query->where('x.entrenador_id', (int) $filtros['entrenador_id']);
        }

        if (! empty($filtros['sede_id'])) {
            $query->where('x.sede_id', (int) $filtros['sede_id']);
        }

        if (array_key_exists('estado', $filtros) && $filtros['estado'] !== '' && $filtros['estado'] !== null) {
            $query->where('x.activo', filter_var($filtros['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query
            ->orderByDesc('x.fecha_inicio')
            ->orderBy('x.hora_inicio')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarExcepcion(array $datos, ?int $id = null): object
    {
        $fechaInicio = Carbon::parse($datos['fecha_inicio']);
        $fechaFin = Carbon::parse($datos['fecha_fin']);

        $horaInicio = $datos['hora_inicio'] ?? null;
        $horaFin = $datos['hora_fin'] ?? null;

        $reservasAfectadas = DB::table('gimnasio.reservas_dia')
            ->where('entrenador_id', (int) $datos['entrenador_id'])
            ->where('sede_id', (int) $datos['sede_id'])
            ->whereBetween('fecha', [$fechaInicio->toDateString(), $fechaFin->toDateString()])
            ->whereIn('estado', self::ESTADOS_RESERVA_ACTIVA)
            ->when($horaInicio && $horaFin, function ($q) use ($horaInicio, $horaFin): void {
                $q->where('hora_inicio', '<', $horaFin)
                    ->where('hora_fin', '>', $horaInicio);
            })
            ->count();

        if ($reservasAfectadas > 0) {
            throw ValidationException::withMessages([
                'fecha_inicio' => "No se puede aplicar la excepción. Existen {$reservasAfectadas} reserva(s) activa(s) dentro del rango. Reagenda o cancela esas reservas primero.",
            ]);
        }

        $antes = $id ? DB::table('gimnasio.excepciones_horario')->where('id', $id)->first() : null;

        $payload = [
            'entrenador_id' => (int) $datos['entrenador_id'],
            'sede_id' => (int) $datos['sede_id'],
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $fechaFin->toDateString(),
            'hora_inicio' => $horaInicio,
            'hora_fin' => $horaFin,
            'tipo' => $datos['tipo'] ?? 'NO_DISPONIBLE',
            'motivo' => trim($datos['motivo']),
            'observaciones' => $datos['observaciones'] ?? null,
            'activo' => $datos['activo'] ?? true,
            'updated_at' => now(),
        ];

        if ($id) {
            DB::table('gimnasio.excepciones_horario')->where('id', $id)->update($payload);
            $excepcionId = $id;
        } else {
            $payload['created_at'] = now();
            $excepcionId = DB::table('gimnasio.excepciones_horario')->insertGetId($payload);
        }

        $despues = DB::table('gimnasio.excepciones_horario')->where('id', $excepcionId)->first();
        $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'gimnasio.excepciones_horario', $excepcionId, $antes, $despues);

        return $despues;
    }

    private function buscarExcepcion(
        int $entrenadorId,
        int $sedeId,
        string $fecha,
        string $horaInicio,
        string $horaFin
    ): ?object {
        return DB::table('gimnasio.excepciones_horario')
            ->where('entrenador_id', $entrenadorId)
            ->where('sede_id', $sedeId)
            ->where('activo', true)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->where(function ($q) use ($horaInicio, $horaFin): void {
                $q->whereNull('hora_inicio')
                    ->whereNull('hora_fin')
                    ->orWhere(function ($sub) use ($horaInicio, $horaFin): void {
                        $sub->where('hora_inicio', '<', $horaFin)
                            ->where('hora_fin', '>', $horaInicio);
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function solapa(
        string $inicioA,
        string $finA,
        ?string $inicioB,
        ?string $finB
    ): bool {
        if (! $inicioB || ! $finB) {
            return false;
        }

        return $inicioA < $finB && $finA > $inicioB;
    }
}
