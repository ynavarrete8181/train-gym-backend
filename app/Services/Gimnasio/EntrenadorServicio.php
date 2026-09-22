<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntrenadorServicio
{
    use RegistraAuditoria;

    private const ORDEN_DIAS = ['LUNES' => 1, 'MARTES' => 2, 'MIERCOLES' => 3, 'JUEVES' => 4, 'VIERNES' => 5, 'SABADO' => 6, 'DOMINGO' => 7];

    public function crear(array $datos)
    {
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('gimnasio.entrenadores')->insertGetId($datos);
        $entrenador = DB::table('gimnasio.entrenadores')->where('id', $id)->first();
        $this->auditar('gimnasio', 'CREAR', 'gimnasio.entrenadores', $id, null, $entrenador);
        return $entrenador;
    }

    public function actualizar(int $id, array $datos)
    {
        $antes = DB::table('gimnasio.entrenadores')->where('id', $id)->first();
        $datos['updated_at'] = now();

        DB::table('gimnasio.entrenadores')->where('id', $id)->update($datos);
        $entrenador = DB::table('gimnasio.entrenadores')->where('id', $id)->first();
        $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.entrenadores', $id, $antes, $entrenador);
        return $entrenador;
    }

    public function obtenerPorUsuario(int $usuarioId): ?object
    {
        return DB::table('gimnasio.entrenadores')
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'ACTIVO')
            ->first();
    }

    /**
     * Horarios (bloques configurados en Servicios y Agenda) ya asignados a este entrenador.
     */
    public function listarTurnos(int $entrenadorId, ?int $sedeId = null): array
    {
        $items = DB::table('gimnasio.horario_entrenadores as he')
            ->join('gimnasio.horario_bloques as hb', 'he.horario_bloque_id', '=', 'hb.id')
            ->join('gimnasio.servicios as sv', 'hb.servicio_id', '=', 'sv.id')
            ->leftJoin('gimnasio.horarios_servicio as hs', function ($join): void {
                $join->on('hs.horario_bloque_id', '=', 'hb.id')->where('hs.activo', true);
            })
            ->leftJoin('institucional.sedes as sd', 'hs.sede_id', '=', 'sd.id_sede')
            ->where('he.entrenador_id', $entrenadorId)
            ->where('he.activo', true)
            ->when($sedeId, fn ($q) => $q->where('hs.sede_id', $sedeId))
            ->groupBy('hb.id', 'hb.nombre', 'hb.activo', 'sv.nombre')
            ->select(
                'hb.id',
                'hb.nombre',
                'hb.activo',
                'sv.nombre as servicio_nombre',
                DB::raw('MIN(hs.hora_inicio) as hora_inicio'),
                DB::raw('MAX(hs.hora_fin) as hora_fin'),
                DB::raw('MAX(hs.capacidad) as capacidad'),
                DB::raw("STRING_AGG(DISTINCT sd.nombre, ', ') as sede_nombre"),
                DB::raw("STRING_AGG(DISTINCT hs.dia_semana, ',') as dias_text")
            )
            ->orderBy('hora_inicio')
            ->get();

        return $this->mapearDias($items)->all();
    }

    /**
     * Horarios (bloques) configurados en Servicios y Agenda que este entrenador
     * todavía NO tiene asignados, para poder seleccionarlos desde su ficha.
     */
    public function listarHorariosDisponibles(int $entrenadorId): array
    {
        $asignadosIds = DB::table('gimnasio.horario_entrenadores')
            ->where('entrenador_id', $entrenadorId)
            ->where('activo', true)
            ->pluck('horario_bloque_id');

        $items = DB::table('gimnasio.horario_bloques as hb')
            ->join('gimnasio.servicios as sv', 'hb.servicio_id', '=', 'sv.id')
            ->leftJoin('gimnasio.horarios_servicio as hs', function ($join): void {
                $join->on('hs.horario_bloque_id', '=', 'hb.id')->where('hs.activo', true);
            })
            ->leftJoin('institucional.sedes as sd', 'hs.sede_id', '=', 'sd.id_sede')
            ->where('hb.activo', true)
            ->when($asignadosIds->isNotEmpty(), fn ($q) => $q->whereNotIn('hb.id', $asignadosIds))
            ->groupBy('hb.id', 'hb.nombre', 'hb.activo', 'sv.nombre')
            ->select(
                'hb.id',
                'hb.nombre',
                'hb.activo',
                'sv.nombre as servicio_nombre',
                DB::raw('MIN(hs.hora_inicio) as hora_inicio'),
                DB::raw('MAX(hs.hora_fin) as hora_fin'),
                DB::raw('MAX(hs.capacidad) as capacidad'),
                DB::raw("STRING_AGG(DISTINCT sd.nombre, ', ') as sede_nombre"),
                DB::raw("STRING_AGG(DISTINCT hs.dia_semana, ',') as dias_text")
            )
            ->orderBy('sv.nombre')
            ->orderBy('hora_inicio')
            ->get();

        return $this->mapearDias($items)->all();
    }

    public function asignarHorario(int $entrenadorId, int $horarioBloqueId): object
    {
        $bloque = DB::table('gimnasio.horario_bloques')->where('id', $horarioBloqueId)->first();

        if (! $bloque || ! $bloque->activo) {
            throw ValidationException::withMessages([
                'horario_bloque_id' => 'El horario seleccionado no existe o está inactivo.',
            ]);
        }

        $existente = DB::table('gimnasio.horario_entrenadores')
            ->where('entrenador_id', $entrenadorId)
            ->where('horario_bloque_id', $horarioBloqueId)
            ->first();

        if ($existente && $existente->activo) {
            throw ValidationException::withMessages([
                'horario_bloque_id' => 'El entrenador ya está asignado a este horario.',
            ]);
        }

        $this->validarSolapamientoHorario($entrenadorId, $horarioBloqueId);

        if ($existente) {
            DB::table('gimnasio.horario_entrenadores')
                ->where('id', $existente->id)
                ->update(['activo' => true, 'updated_at' => now()]);
        } else {
            DB::table('gimnasio.horario_entrenadores')->insert([
                'entrenador_id' => $entrenadorId,
                'horario_bloque_id' => $horarioBloqueId,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->auditar('gimnasio', 'CREAR', 'gimnasio.horario_entrenadores', $entrenadorId, null, (object) ['entrenador_id' => $entrenadorId, 'horario_bloque_id' => $horarioBloqueId], 'Horario asignado al entrenador.');

        return (object) ['entrenador_id' => $entrenadorId, 'horario_bloque_id' => $horarioBloqueId];
    }

    public function quitarHorario(int $entrenadorId, int $horarioBloqueId): void
    {
        $tieneAsignaciones = DB::table('gimnasio.asignaciones_entrenador_cliente')
            ->where('entrenador_id', $entrenadorId)
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('estado', 'ACTIVO')
            ->exists();

        if ($tieneAsignaciones) {
            DB::table('gimnasio.horario_entrenadores')
                ->where('entrenador_id', $entrenadorId)
                ->where('horario_bloque_id', $horarioBloqueId)
                ->update(['activo' => false, 'updated_at' => now()]);
            $this->auditar('gimnasio', 'ACTUALIZAR', 'gimnasio.horario_entrenadores', $entrenadorId, null, null, 'Horario desactivado del entrenador (tenía clientes activos).');
            return;
        }

        DB::table('gimnasio.horario_entrenadores')
            ->where('entrenador_id', $entrenadorId)
            ->where('horario_bloque_id', $horarioBloqueId)
            ->delete();
        $this->auditar('gimnasio', 'ELIMINAR', 'gimnasio.horario_entrenadores', $entrenadorId, null, null, 'Horario quitado del entrenador.');
    }

    private function validarSolapamientoHorario(int $entrenadorId, int $horarioBloqueId): void
    {
        $detallesNuevos = DB::table('gimnasio.horarios_servicio')
            ->where('horario_bloque_id', $horarioBloqueId)
            ->where('activo', true)
            ->get(['dia_semana', 'hora_inicio', 'hora_fin']);

        if ($detallesNuevos->isEmpty()) {
            throw ValidationException::withMessages([
                'horario_bloque_id' => 'El horario seleccionado no tiene días y horas activos configurados.',
            ]);
        }

        foreach ($detallesNuevos as $nuevo) {
            $conflicto = DB::table('gimnasio.horario_entrenadores as he')
                ->join('gimnasio.horario_bloques as hb', 'hb.id', '=', 'he.horario_bloque_id')
                ->join('gimnasio.horarios_servicio as hs', function ($join): void {
                    $join->on('hs.horario_bloque_id', '=', 'hb.id')
                        ->where('hs.activo', true);
                })
                ->leftJoin('institucional.sedes as sd', 'sd.id_sede', '=', 'hs.sede_id')
                ->where('he.entrenador_id', $entrenadorId)
                ->where('he.activo', true)
                ->where('hb.activo', true)
                ->where('hb.id', '!=', $horarioBloqueId)
                ->where('hs.dia_semana', $nuevo->dia_semana)
                ->where('hs.hora_inicio', '<', $nuevo->hora_fin)
                ->where('hs.hora_fin', '>', $nuevo->hora_inicio)
                ->select('hb.nombre', 'hs.dia_semana', 'hs.hora_inicio', 'hs.hora_fin', 'sd.nombre as sede_nombre')
                ->first();

            if ($conflicto) {
                $inicio = substr((string) $conflicto->hora_inicio, 0, 5);
                $fin = substr((string) $conflicto->hora_fin, 0, 5);
                $sede = $conflicto->sede_nombre ?: 'sede no especificada';

                throw ValidationException::withMessages([
                    'horario_bloque_id' => "El entrenador ya tiene el horario '{$conflicto->nombre}' el {$conflicto->dia_semana} de {$inicio} a {$fin} en {$sede}. No se pueden asignar horarios superpuestos.",
                ]);
            }
        }
    }

    private function mapearDias($items)
    {
        return collect($items)->map(function ($fila) {
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
}
