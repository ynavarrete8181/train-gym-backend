<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsignacionEntrenadorClienteServicio
{
    use RegistraAuditoria;

    private const ORDEN_DIAS = ['LUNES' => 1, 'MARTES' => 2, 'MIERCOLES' => 3, 'JUEVES' => 4, 'VIERNES' => 5, 'SABADO' => 6, 'DOMINGO' => 7];

    public function listarPorEntrenador(int $entrenadorId, ?string $estado = 'ACTIVO'): array
    {
        $items = $this->consultaBase()
            ->where('a.entrenador_id', $entrenadorId)
            ->when($estado, fn ($q) => $q->where('a.estado', $estado))
            ->orderBy('hb.hora_inicio')
            ->get();

        return $this->mapearDias($items)->all();
    }

    public function listarPorDeportista(int $deportistaId, ?string $estado = 'ACTIVO'): array
    {
        $items = $this->consultaBase()
            ->where('a.deportista_id', $deportistaId)
            ->when($estado, fn ($q) => $q->where('a.estado', $estado))
            ->orderBy('hb.hora_inicio')
            ->get();

        return $this->mapearDias($items)->all();
    }

    public function asignar(array $datos): object
    {
        return DB::transaction(function () use ($datos) {
            if (! empty($datos['entrenador_horario_id'])) {
                $this->validarHorarioVigentePerteneceAEntrenador(
                    (int) $datos['entrenador_id'],
                    (int) $datos['entrenador_horario_id']
                );
                $this->validarDuplicadoHorarioVigente(
                    (int) $datos['deportista_id'],
                    (int) $datos['entrenador_id'],
                    (int) $datos['entrenador_horario_id']
                );
                $this->validarCapacidadHorarioVigente(
                    (int) $datos['entrenador_id'],
                    (int) $datos['entrenador_horario_id']
                );
            } elseif (! empty($datos['horario_bloque_id'])) {
                $this->validarHorarioPerteneceAEntrenador((int) $datos['entrenador_id'], (int) $datos['horario_bloque_id']);
                $this->validarDuplicado((int) $datos['deportista_id'], (int) $datos['horario_bloque_id']);
                $this->validarCapacidad((int) $datos['horario_bloque_id']);
            }

            $datos['tipo_asignacion'] = $datos['tipo_asignacion'] ?? 'SEGUIMIENTO';
            $datos['estado'] = 'ACTIVO';
            $datos['fecha_inicio'] = $datos['fecha_inicio'] ?? now()->toDateString();
            $datos['created_at'] = now();
            $datos['updated_at'] = now();

            $id = DB::table('entrenamiento.asignaciones_entrenador_cliente')->insertGetId($datos);

            $asignacion = $this->mapearDias(collect([$this->consultaBase()->where('a.id', $id)->first()]))->first();
            $this->auditar('entrenamiento', 'CREAR', 'entrenamiento.asignaciones_entrenador_cliente', $id, null, $asignacion);
            return $asignacion;
        });
    }

    public function actualizarObservaciones(int $id, ?string $observaciones): object
    {
        return DB::transaction(function () use ($id, $observaciones) {
            $antes = DB::table('entrenamiento.asignaciones_entrenador_cliente')
                ->where('id', $id)
                ->first();

            if (! $antes) {
                throw ValidationException::withMessages([
                    'id' => 'La asignación no existe.',
                ]);
            }

            if (strtoupper((string) $antes->estado) !== 'ACTIVO') {
                throw ValidationException::withMessages([
                    'id' => 'Solo se pueden editar las observaciones de la asignación vigente.',
                ]);
            }

            DB::table('entrenamiento.asignaciones_entrenador_cliente')
                ->where('id', $id)
                ->update([
                    'observaciones' => $observaciones,
                    'updated_at' => now(),
                ]);

            $despues = $this->mapearDias(
                collect([$this->consultaBase()->where('a.id', $id)->first()])
            )->first();

            $this->auditar(
                'entrenamiento',
                'ACTUALIZAR',
                'entrenamiento.asignaciones_entrenador_cliente',
                $id,
                $antes,
                $despues,
                'Observaciones de asignación actualizadas.'
            );

            return $despues;
        });
    }

    public function finalizar(int $id): object
    {
        $antes = DB::table('entrenamiento.asignaciones_entrenador_cliente')->where('id', $id)->first();

        DB::table('entrenamiento.asignaciones_entrenador_cliente')
            ->where('id', $id)
            ->update(['estado' => 'FINALIZADO', 'fecha_fin' => now()->toDateString(), 'updated_at' => now()]);

        $despues = DB::table('entrenamiento.asignaciones_entrenador_cliente')->where('id', $id)->first();
        $this->auditar('entrenamiento', 'ACTUALIZAR', 'entrenamiento.asignaciones_entrenador_cliente', $id, $antes, $despues, 'Asignación finalizada.');
        return $despues;
    }

    private function consultaBase()
    {
        $bloques = DB::table('agenda.horario_bloques as hb')
            ->join('servicios.servicios as sv', 'hb.servicio_id', '=', 'sv.id')
            ->leftJoin('agenda.horarios_servicio as hs', function ($join): void {
                $join->on('hs.horario_bloque_id', '=', 'hb.id')->where('hs.activo', true);
            })
            ->leftJoin('institucional.sedes as sd', 'hs.sede_id', '=', 'sd.id_sede')
            ->groupBy('hb.id', 'hb.nombre', 'sv.nombre')
            ->select(
                'hb.id',
                'hb.nombre',
                'sv.nombre as servicio_nombre',
                DB::raw('MIN(hs.hora_inicio) as hora_inicio'),
                DB::raw('MAX(hs.hora_fin) as hora_fin'),
                DB::raw('MAX(hs.capacidad) as capacidad'),
                DB::raw("STRING_AGG(DISTINCT sd.nombre, ', ') as sede_nombre"),
                DB::raw("STRING_AGG(DISTINCT hs.dia_semana, ',') as dias_text")
            );

        $horariosVigentes = DB::table('agenda.entrenador_horarios as eh')
            ->leftJoin('agenda.entrenador_horario_franjas as ehf', function ($join): void {
                $join->on('ehf.entrenador_horario_id', '=', 'eh.id')->where('ehf.activo', true);
            })
            ->leftJoin('institucional.sedes as esd', 'esd.id_sede', '=', 'ehf.sede_id')
            ->groupBy('eh.id', 'eh.tipo_horario', 'eh.fecha_inicio', 'eh.fecha_fin')
            ->select(
                'eh.id',
                'eh.tipo_horario',
                'eh.fecha_inicio',
                'eh.fecha_fin',
                DB::raw('MIN(ehf.hora_inicio) as hora_inicio'),
                DB::raw('MAX(ehf.hora_fin) as hora_fin'),
                DB::raw("STRING_AGG(DISTINCT ehf.dia_semana, ',') as dias_text"),
                DB::raw("STRING_AGG(DISTINCT esd.nombre, ', ') as sede_nombre")
            );

        return DB::table('entrenamiento.asignaciones_entrenador_cliente as a')
            ->leftJoinSub($bloques, 'hb', 'a.horario_bloque_id', '=', 'hb.id')
            ->leftJoinSub($horariosVigentes, 'eh', 'a.entrenador_horario_id', '=', 'eh.id')
            ->join('entrenamiento.entrenadores as e', 'a.entrenador_id', '=', 'e.id')
            ->leftJoin('personas.personas as pe', 'e.persona_id', '=', 'pe.id')
            ->leftJoin('seguridad.users as ue', 'e.usuario_id', '=', 'ue.id')
            ->join('clientes.deportistas as d', 'a.deportista_id', '=', 'd.id')
            ->leftJoin('personas.personas as pd', 'd.persona_id', '=', 'pd.id')
            ->leftJoin('seguridad.users as ud', 'd.usuario_id', '=', 'ud.id')
            ->select(
                'a.*',
                DB::raw("COALESCE(hb.nombre, CASE WHEN eh.id IS NOT NULL THEN 'Horario vigente' END) as horario_nombre"),
                'hb.servicio_nombre',
                DB::raw('COALESCE(eh.dias_text, hb.dias_text) as dias_text'),
                DB::raw('COALESCE(eh.hora_inicio, hb.hora_inicio) as hora_inicio'),
                DB::raw('COALESCE(eh.hora_fin, hb.hora_fin) as hora_fin'),
                'hb.capacidad',
                DB::raw('COALESCE(eh.sede_nombre, hb.sede_nombre) as sede_nombre'),
                'eh.tipo_horario as horario_tipo',
                'eh.fecha_inicio as horario_fecha_inicio',
                'eh.fecha_fin as horario_fecha_fin',
                DB::raw('COALESCE(pe.nombre_completo, ue.name) as entrenador_nombre'),
                DB::raw('COALESCE(pe.nombres, ue.nombres) as entrenador_nombres'),
                DB::raw('COALESCE(pe.apellidos, ue.apellidos) as entrenador_apellidos'),
                DB::raw('COALESCE(pd.nombre_completo, ud.name) as deportista_nombre'),
                DB::raw('COALESCE(pd.nombres, pd.nombre_completo, ud.nombres) as deportista_nombres'),
                DB::raw('COALESCE(pd.apellidos, ud.apellidos) as deportista_apellidos'),
                'd.codigo_deportista'
            );
    }

    private function mapearDias($items)
    {
        return collect($items)->filter()->map(function ($fila) {
            $dias = collect(explode(',', $fila->dias_text ?? ''))
                ->filter()
                ->unique()
                ->sortBy(fn ($dia) => self::ORDEN_DIAS[$dia] ?? 99)
                ->values();

            $fila->dia_semana = $dias->implode(', ');
            unset($fila->dias_text);

            return $fila;
        })->values();
    }

    private function validarCapacidadHorarioVigente(int $entrenadorId, int $entrenadorHorarioId): void
    {
        $capacidad = (int) (DB::table('agenda.entrenador_horarios')
            ->where('id', $entrenadorHorarioId)
            ->where('entrenador_id', $entrenadorId)
            ->value('capacidad') ?? 15);

        $asignados = DB::table('entrenamiento.asignaciones_entrenador_cliente')
            ->where('entrenador_id', $entrenadorId)
            ->where('entrenador_horario_id', $entrenadorHorarioId)
            ->where('estado', 'ACTIVO')
            ->count();

        if ($asignados >= $capacidad) {
            throw ValidationException::withMessages([
                'entrenador_horario_id' => 'El horario del entrenador ya alcanzó su capacidad máxima.',
            ]);
        }
    }

    private function validarHorarioVigentePerteneceAEntrenador(int $entrenadorId, int $entrenadorHorarioId): void
    {
        $hoy = now()->toDateString();

        $existe = DB::table('agenda.entrenador_horarios')
            ->where('id', $entrenadorHorarioId)
            ->where('entrenador_id', $entrenadorId)
            ->where('activo', true)
            ->where(function ($q) use ($hoy): void {
                $q->whereNull('fecha_inicio')->orWhereDate('fecha_inicio', '<=', $hoy);
            })
            ->where(function ($q) use ($hoy): void {
                $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $hoy);
            })
            ->exists();

        if (! $existe) {
            throw ValidationException::withMessages([
                'entrenador_horario_id' => 'El horario seleccionado no pertenece al entrenador o no está vigente.',
            ]);
        }
    }

    private function validarDuplicadoHorarioVigente(int $deportistaId, int $entrenadorId, int $entrenadorHorarioId): void
    {
        $existe = DB::table('entrenamiento.asignaciones_entrenador_cliente')
            ->where('deportista_id', $deportistaId)
            ->where('entrenador_id', $entrenadorId)
            ->where('entrenador_horario_id', $entrenadorHorarioId)
            ->where('estado', 'ACTIVO')
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'deportista_id' => 'El cliente ya está asignado a este entrenador con ese horario vigente.',
            ]);
        }
    }

    private function validarHorarioPerteneceAEntrenador(int $entrenadorId, int $horarioBloqueId): void
    {
        $existe = DB::table('agenda.horario_entrenadores')
            ->where('entrenador_id', $entrenadorId)
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('activo', true)
            ->exists();

        if (! $existe) {
            throw ValidationException::withMessages([
                'horario_bloque_id' => 'El horario no pertenece al entrenador seleccionado.',
            ]);
        }
    }

    private function validarDuplicado(int $deportistaId, int $horarioBloqueId): void
    {
        $existe = DB::table('entrenamiento.asignaciones_entrenador_cliente')
            ->where('deportista_id', $deportistaId)
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('estado', 'ACTIVO')
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'deportista_id' => 'El cliente ya tiene una asignación activa en ese horario.',
            ]);
        }
    }

    private function validarCapacidad(int $horarioBloqueId): void
    {
        $capacidad = DB::table('agenda.horarios_servicio')
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('activo', true)
            ->max('capacidad');

        if (! $capacidad) {
            return;
        }

        $ocupados = DB::table('entrenamiento.asignaciones_entrenador_cliente')
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('estado', 'ACTIVO')
            ->count();

        if ($ocupados >= (int) $capacidad) {
            throw ValidationException::withMessages([
                'horario_bloque_id' => 'El horario seleccionado ya alcanzó su capacidad.',
            ]);
        }
    }
}
