<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaConfiguracionServicio
{
    use RegistraAuditoria;

    private const ORDEN_DIAS = [
        'LUNES' => 1,
        'MARTES' => 2,
        'MIERCOLES' => 3,
        'JUEVES' => 4,
        'VIERNES' => 5,
        'SABADO' => 6,
        'DOMINGO' => 7,
    ];

    public function listarJornadas(array $filtros)
    {
        $query = DB::table('gimnasio.jornadas as j')
            ->leftJoin('gimnasio.jornada_detalles as jd', function ($join): void {
                $join->on('jd.jornada_id', '=', 'j.id')->where('jd.activo', true);
            })
            ->select(
                'j.*',
                DB::raw('MIN(jd.hora_inicio) as hora_inicio'),
                DB::raw('MAX(jd.hora_fin) as hora_fin'),
                DB::raw("STRING_AGG(DISTINCT jd.dia_semana, ',') as dias_text")
            )
            ->groupBy('j.id');

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower(trim($filtros['busqueda'])) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(j.nombre) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(j.descripcion, '')) LIKE ?", [$texto]);
            });
        }

        if (array_key_exists('estado', $filtros) && $filtros['estado'] !== '' && $filtros['estado'] !== null) {
            $query->where('j.activo', filter_var($filtros['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginador = $query
            ->orderBy('j.nombre')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);

        $paginador->getCollection()->transform(fn ($fila) => $this->mapearJornada($fila));

        return $paginador;
    }

    public function listarJornadasActivas(): array
    {
        return $this->listarJornadas(['per_page' => 500, 'page' => 1, 'estado' => true])->items();
    }

    public function guardarJornada(array $datos, ?int $id = null): object
    {
        return DB::transaction(function () use ($datos, $id): object {
            $antes = $id ? DB::table('gimnasio.jornadas')->where('id', $id)->first() : null;

            $cabecera = [
                'nombre' => trim($datos['nombre']),
                'descripcion' => $datos['descripcion'] ?? null,
                'activo' => $datos['activo'] ?? true,
                'updated_at' => now(),
            ];

            if ($id) {
                DB::table('gimnasio.jornadas')->where('id', $id)->update($cabecera);
                $jornadaId = $id;
            } else {
                $cabecera['created_at'] = now();
                $jornadaId = DB::table('gimnasio.jornadas')->insertGetId($cabecera);
            }

            $dias = array_values(array_unique($datos['dias_semana']));
            DB::table('gimnasio.jornada_detalles')
                ->where('jornada_id', $jornadaId)
                ->whereNotIn('dia_semana', $dias)
                ->update(['activo' => false, 'updated_at' => now()]);

            foreach ($dias as $dia) {
                DB::table('gimnasio.jornada_detalles')->updateOrInsert(
                    ['jornada_id' => $jornadaId, 'dia_semana' => $dia],
                    [
                        'hora_inicio' => $datos['hora_inicio'],
                        'hora_fin' => $datos['hora_fin'],
                        'activo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $despues = $this->obtenerJornada($jornadaId);
            $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'gimnasio.jornadas', $jornadaId, $antes, $despues);

            return $despues;
        });
    }

    public function obtenerJornada(int $id): object
    {
        $jornada = DB::table('gimnasio.jornadas')->where('id', $id)->first();

        if (! $jornada) {
            throw ValidationException::withMessages(['jornada_id' => 'La jornada no existe.']);
        }

        $detalles = DB::table('gimnasio.jornada_detalles')
            ->where('jornada_id', $id)
            ->where('activo', true)
            ->get(['dia_semana', 'hora_inicio', 'hora_fin']);

        $jornada->dias_semana = $detalles
            ->pluck('dia_semana')
            ->sortBy(fn ($dia) => self::ORDEN_DIAS[$dia] ?? 99)
            ->values()
            ->all();
        $jornada->hora_inicio = $detalles->min('hora_inicio');
        $jornada->hora_fin = $detalles->max('hora_fin');

        return $jornada;
    }

    public function listarRecesos(array $filtros)
    {
        $query = DB::table('gimnasio.recesos');

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower(trim($filtros['busqueda'])) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(tipo, '')) LIKE ?", [$texto]);
            });
        }

        if (array_key_exists('estado', $filtros) && $filtros['estado'] !== '' && $filtros['estado'] !== null) {
            $query->where('activo', filter_var($filtros['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query
            ->orderBy('hora_inicio')
            ->orderBy('nombre')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarReceso(array $datos, ?int $id = null): object
    {
        $antes = $id ? DB::table('gimnasio.recesos')->where('id', $id)->first() : null;
        $datos['updated_at'] = now();

        if ($id) {
            DB::table('gimnasio.recesos')->where('id', $id)->update($datos);
            $recesoId = $id;
        } else {
            $datos['created_at'] = now();
            $recesoId = DB::table('gimnasio.recesos')->insertGetId($datos);
        }

        $despues = DB::table('gimnasio.recesos')->where('id', $recesoId)->first();
        $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'gimnasio.recesos', $recesoId, $antes, $despues);

        return $despues;
    }

    public function listarAsignaciones(array $filtros)
    {
        $query = DB::table('gimnasio.asignaciones_horario_entrenador as a')
            ->join('gimnasio.entrenadores as e', 'e.id', '=', 'a.entrenador_id')
            ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'a.sede_id')
            ->join('gimnasio.jornadas as j', 'j.id', '=', 'a.jornada_id')
            ->leftJoin('gimnasio.recesos as r', 'r.id', '=', 'a.receso_id')
            ->select(
                'a.*',
                'u.name as entrenador_nombre',
                'e.especialidad as entrenador_especialidad',
                's.nombre as sede_nombre',
                'j.nombre as jornada_nombre',
                'r.nombre as receso_nombre',
                'r.hora_inicio as receso_hora_inicio',
                'r.hora_fin as receso_hora_fin'
            );

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower(trim($filtros['busqueda'])) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(u.name) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(s.nombre) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(j.nombre) LIKE ?', [$texto]);
            });
        }

        if (! empty($filtros['entrenador_id'])) {
            $query->where('a.entrenador_id', (int) $filtros['entrenador_id']);
        }

        if (! empty($filtros['sede_id'])) {
            $query->where('a.sede_id', (int) $filtros['sede_id']);
        }

        if (array_key_exists('estado', $filtros) && $filtros['estado'] !== '' && $filtros['estado'] !== null) {
            $query->where('a.activo', filter_var($filtros['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginador = $query
            ->orderBy('u.name')
            ->orderBy('a.fecha_inicio', 'desc')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);

        $jornadas = DB::table('gimnasio.jornada_detalles')
            ->whereIn('jornada_id', collect($paginador->items())->pluck('jornada_id')->filter()->unique())
            ->where('activo', true)
            ->get()
            ->groupBy('jornada_id');

        $paginador->getCollection()->transform(function ($fila) use ($jornadas) {
            $detalles = collect($jornadas->get($fila->jornada_id, []));
            $fila->dias_semana = $detalles
                ->pluck('dia_semana')
                ->sortBy(fn ($dia) => self::ORDEN_DIAS[$dia] ?? 99)
                ->values()
                ->all();
            $fila->hora_inicio = $detalles->min('hora_inicio');
            $fila->hora_fin = $detalles->max('hora_fin');
            return $fila;
        });

        return $paginador;
    }

    public function guardarAsignacion(array $datos, ?int $id = null): object
    {
        $this->validarAsignacion($datos, $id);

        $antes = $id ? DB::table('gimnasio.asignaciones_horario_entrenador')->where('id', $id)->first() : null;
        $datos['updated_at'] = now();

        if ($id) {
            DB::table('gimnasio.asignaciones_horario_entrenador')->where('id', $id)->update($datos);
            $asignacionId = $id;
        } else {
            $datos['created_at'] = now();
            $asignacionId = DB::table('gimnasio.asignaciones_horario_entrenador')->insertGetId($datos);
        }

        $despues = DB::table('gimnasio.asignaciones_horario_entrenador')->where('id', $asignacionId)->first();
        $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'gimnasio.asignaciones_horario_entrenador', $asignacionId, $antes, $despues);

        return $despues;
    }

    public function catalogosAsignacion(): array
    {
        return [
            'entrenadores' => DB::table('gimnasio.entrenadores as e')
                ->join('seguridad.users as u', 'u.id', '=', 'e.usuario_id')
                ->where('e.estado', 'ACTIVO')
                ->orderBy('u.name')
                ->get(['e.id', 'u.name', 'e.especialidad']),
            'sedes' => DB::table('institucional.sedes')
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id_sede as id', 'nombre']),
            'jornadas' => collect($this->listarJornadasActivas()),
            'recesos' => DB::table('gimnasio.recesos')
                ->where('activo', true)
                ->orderBy('hora_inicio')
                ->get(),
        ];
    }

    private function validarAsignacion(array $datos, ?int $ignorarId = null): void
    {
        $jornada = $this->obtenerJornada((int) $datos['jornada_id']);
        $detallesNuevos = DB::table('gimnasio.jornada_detalles')
            ->where('jornada_id', $jornada->id)
            ->where('activo', true)
            ->get();

        if ($detallesNuevos->isEmpty()) {
            throw ValidationException::withMessages(['jornada_id' => 'La jornada seleccionada no tiene días activos.']);
        }

        if (! empty($datos['receso_id'])) {
            $receso = DB::table('gimnasio.recesos')->where('id', $datos['receso_id'])->where('activo', true)->first();

            if (! $receso) {
                throw ValidationException::withMessages(['receso_id' => 'El receso seleccionado no está disponible.']);
            }

            $contenido = $detallesNuevos->contains(fn ($detalle) =>
                $receso->hora_inicio >= $detalle->hora_inicio && $receso->hora_fin <= $detalle->hora_fin
            );

            if (! $contenido) {
                throw ValidationException::withMessages([
                    'receso_id' => 'El receso debe estar contenido dentro del horario de la jornada.',
                ]);
            }
        }

        $fechaInicio = $datos['fecha_inicio'];
        $fechaFin = $datos['fecha_fin'] ?? '9999-12-31';

        $existentes = DB::table('gimnasio.asignaciones_horario_entrenador as a')
            ->join('gimnasio.jornadas as j', 'j.id', '=', 'a.jornada_id')
            ->where('a.entrenador_id', (int) $datos['entrenador_id'])
            ->where('a.activo', true)
            ->when($ignorarId, fn ($q) => $q->where('a.id', '!=', $ignorarId))
            ->whereDate('a.fecha_inicio', '<=', $fechaFin)
            ->where(function ($q) use ($fechaInicio): void {
                $q->whereNull('a.fecha_fin')->orWhereDate('a.fecha_fin', '>=', $fechaInicio);
            })
            ->get(['a.id', 'a.sede_id', 'a.jornada_id', 'j.nombre as jornada_nombre']);

        foreach ($existentes as $existente) {
            $detallesExistentes = DB::table('gimnasio.jornada_detalles')
                ->where('jornada_id', $existente->jornada_id)
                ->where('activo', true)
                ->get();

            foreach ($detallesNuevos as $nuevo) {
                $conflicto = $detallesExistentes->first(fn ($actual) =>
                    $actual->dia_semana === $nuevo->dia_semana
                    && $actual->hora_inicio < $nuevo->hora_fin
                    && $actual->hora_fin > $nuevo->hora_inicio
                );

                if (! $conflicto) continue;

                $sede = DB::table('institucional.sedes')->where('id_sede', $existente->sede_id)->value('nombre') ?? 'otra sede';
                $inicio = substr((string) $conflicto->hora_inicio, 0, 5);
                $fin = substr((string) $conflicto->hora_fin, 0, 5);

                throw ValidationException::withMessages([
                    'jornada_id' => "El entrenador ya tiene '{$existente->jornada_nombre}' el {$conflicto->dia_semana} de {$inicio} a {$fin} en {$sede}. No puede estar asignado en dos sedes al mismo tiempo.",
                ]);
            }
        }
    }

    private function mapearJornada(object $fila): object
    {
        $dias = collect(explode(',', $fila->dias_text ?? ''))
            ->filter()
            ->unique()
            ->sortBy(fn ($dia) => self::ORDEN_DIAS[$dia] ?? 99)
            ->values();

        $fila->dias_semana = $dias->all();
        $fila->dia_semana = $dias->implode(', ');
        unset($fila->dias_text);

        return $fila;
    }
}
