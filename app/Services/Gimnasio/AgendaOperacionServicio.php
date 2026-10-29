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
            'servicios' => DB::table('servicios.servicios')
                ->where('activo', true)
                ->where('requiere_reserva', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'duracion_minutos', 'capacidad_base']),
        ];
    }

    public function serviciosDisponibles(int $entrenadorId, int $sedeId): array
    {
        // El catálogo de servicios habilitados es independiente de la jornada.
        // La vigencia, sede y franjas se verifican al calcular la disponibilidad
        // para la fecha seleccionada, no al poblar este selector.
        return DB::table('entrenamiento.entrenador_servicios as es')
            ->join('servicios.servicios as s', 's.id', '=', 'es.servicio_id')
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

        $servicio = DB::table('servicios.servicios as s')
            ->join('entrenamiento.entrenador_servicios as es', function ($join) use ($entrenadorId): void {
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

        $franjas = DB::table('agenda.entrenador_horarios as h')
            ->join('agenda.entrenador_horario_franjas as f', 'f.entrenador_horario_id', '=', 'h.id')
            ->where('h.entrenador_id', $entrenadorId)
            ->where('h.activo', true)
            ->where('f.activo', true)
            ->where('f.sede_id', $sedeId)
            ->where('f.dia_semana', $dia)
            ->whereDate('h.fecha_inicio', '<=', $fecha->toDateString())
            ->where(function ($q) use ($fecha): void {
                $q->whereNull('h.fecha_fin')
                    ->orWhereDate('h.fecha_fin', '>=', $fecha->toDateString());
            })
            ->get(['h.id as horario_id', 'f.id as franja_id', 'f.hora_inicio', 'f.hora_fin']);

        $bloques = collect();

        foreach ($franjas as $franja) {
            $inicio = Carbon::parse($fecha->toDateString() . ' ' . $franja->hora_inicio);
            $finJornada = Carbon::parse($fecha->toDateString() . ' ' . $franja->hora_fin);
            $recesos = DB::table('agenda.entrenador_horario_recesos')
                ->where('entrenador_horario_id', $franja->horario_id)
                ->where('dia_semana', $dia)
                ->where('activo', true)
                ->get(['hora_inicio', 'hora_fin', 'descripcion', 'tipo']);
            $duracion = max(5, (int) $servicio->duracion_minutos);
            $capacidad = max(1, (int) $servicio->capacidad_base);

            while ($inicio->copy()->addMinutes($duracion)->lte($finJornada)) {
                $fin = $inicio->copy()->addMinutes($duracion);

                $estado = 'DISPONIBLE';
                $motivo = null;

                foreach ($recesos as $receso) {
                    if ($this->solapa(
                        $inicio->format('H:i:s'),
                        $fin->format('H:i:s'),
                        $receso->hora_inicio,
                        $receso->hora_fin
                    )) {
                        $estado = 'RECESO';
                        $motivo = $receso->descripcion ?: $receso->tipo;
                        break;
                    }
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

                $reservas = DB::table('agenda.reservas_dia')
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
                    'asignacion_id' => null,
                    'entrenador_horario_id' => $franja->horario_id,
                    'franja_id' => $franja->franja_id,
                    'jornada' => 'Horario personalizado',
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
        $query = DB::table('agenda.reservas_dia as r')
            ->join('clientes.deportistas as d', 'd.id', '=', 'r.cliente_id')
            ->join('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->join('servicios.servicios as s', 's.id', '=', 'r.servicio_id')
            ->leftJoin('entrenamiento.entrenadores as e', 'e.id', '=', 'r.entrenador_id')
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
        $query = DB::table('agenda.excepciones_horario as x')
            ->join('entrenamiento.entrenadores as e', 'e.id', '=', 'x.entrenador_id')
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

        $reservasAfectadas = DB::table('agenda.reservas_dia')
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

        $antes = $id ? DB::table('agenda.excepciones_horario')->where('id', $id)->first() : null;

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
            DB::table('agenda.excepciones_horario')->where('id', $id)->update($payload);
            $excepcionId = $id;
        } else {
            $payload['created_at'] = now();
            $excepcionId = DB::table('agenda.excepciones_horario')->insertGetId($payload);
        }

        $despues = DB::table('agenda.excepciones_horario')->where('id', $excepcionId)->first();
        $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'agenda.excepciones_horario', $excepcionId, $antes, $despues);

        return $despues;
    }

    private function buscarExcepcion(
        int $entrenadorId,
        int $sedeId,
        string $fecha,
        string $horaInicio,
        string $horaFin
    ): ?object {
        return DB::table('agenda.excepciones_horario')
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
