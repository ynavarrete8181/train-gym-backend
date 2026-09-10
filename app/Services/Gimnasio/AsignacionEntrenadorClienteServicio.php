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
            if (! empty($datos['horario_bloque_id'])) {
                $this->validarHorarioPerteneceAEntrenador((int) $datos['entrenador_id'], (int) $datos['horario_bloque_id']);
                $this->validarDuplicado((int) $datos['deportista_id'], (int) $datos['horario_bloque_id']);
                $this->validarCapacidad((int) $datos['horario_bloque_id']);
            }

            $datos['tipo_asignacion'] = $datos['tipo_asignacion'] ?? 'SEGUIMIENTO';
            $datos['estado'] = 'ACTIVO';
            $datos['fecha_inicio'] = $datos['fecha_inicio'] ?? now()->toDateString();
            $datos['created_at'] = now();
            $datos['updated_at'] = now();

            $id = DB::table('gimnasio.asignaciones_entrenador_cliente')->insertGetId($datos);

            $asignacion = $this->mapearDias(collect([$this->consultaBase()->where('a.id', $id)->first()]))->first();
            $this->auditar('gimnasio', 'CREAR', 'gimnasio.asignaciones_entrenador_cliente', $id, null, $asignacion);
            return $asignacion;
        });
    }

    public function finalizar(int $id): object
    {
        $antes = DB::table('gimnasio.asignaciones_entrenador_cliente')->where('id', $id)->first();

        DB::table('gimnasio.asignaciones_entrenador_cliente')
            ->where('id', $id)
            ->update(['estado' => 'FINALIZADO', 'fecha_fin' => now()->toDateString(), 'updated_at' => now()]);

        $despues = DB::table('gimnasio.asignaciones_entrenador_cliente')->where('id', $id)->first();
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.asignaciones_entrenador_cliente', $id, $antes, $despues, 'Asignación finalizada.');
        return $despues;
    }

    private function consultaBase()
    {
        $bloques = DB::table('gimnasio.horario_bloques as hb')
            ->join('gimnasio.servicios as sv', 'hb.servicio_id', '=', 'sv.id')
            ->leftJoin('gimnasio.horarios_servicio as hs', function ($join): void {
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

        return DB::table('gimnasio.asignaciones_entrenador_cliente as a')
            ->leftJoinSub($bloques, 'hb', 'a.horario_bloque_id', '=', 'hb.id')
            ->join('gimnasio.entrenadores as e', 'a.entrenador_id', '=', 'e.id')
            ->join('seguridad.users as ue', 'e.usuario_id', '=', 'ue.id')
            ->join('gimnasio.deportistas as d', 'a.deportista_id', '=', 'd.id')
            ->join('seguridad.users as ud', 'd.usuario_id', '=', 'ud.id')
            ->select(
                'a.*',
                'hb.nombre as horario_nombre',
                'hb.servicio_nombre',
                'hb.dias_text',
                'hb.hora_inicio', 'hb.hora_fin', 'hb.capacidad',
                'hb.sede_nombre',
                'ue.name as entrenador_nombre', 'ue.nombres as entrenador_nombres', 'ue.apellidos as entrenador_apellidos',
                'ud.name as deportista_nombre', 'ud.nombres as deportista_nombres', 'ud.apellidos as deportista_apellidos',
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

    private function validarHorarioPerteneceAEntrenador(int $entrenadorId, int $horarioBloqueId): void
    {
        $existe = DB::table('gimnasio.horario_entrenadores')
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
        $existe = DB::table('gimnasio.asignaciones_entrenador_cliente')
            ->where('deportista_id', $deportistaId)
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('estado', 'ACTIVO')
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'deportista_id' => 'El cliente ya está asignado a este entrenador en ese horario.',
            ]);
        }
    }

    private function validarCapacidad(int $horarioBloqueId): void
    {
        $capacidad = DB::table('gimnasio.horarios_servicio')
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('activo', true)
            ->max('capacidad');

        if (! $capacidad) {
            return;
        }

        $ocupados = DB::table('gimnasio.asignaciones_entrenador_cliente')
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
