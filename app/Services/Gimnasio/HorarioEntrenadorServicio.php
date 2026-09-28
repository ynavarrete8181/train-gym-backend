<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HorarioEntrenadorServicio
{
    use RegistraAuditoria;

    private const ORDEN_DIAS = ['LUNES'=>1,'MARTES'=>2,'MIERCOLES'=>3,'JUEVES'=>4,'VIERNES'=>5,'SABADO'=>6,'DOMINGO'=>7];

    public function catalogos(): array
    {
        return [
            'sedes' => DB::table('institucional.sedes')
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id_sede as id', 'nombre']),
            'tipos_receso' => ['DESAYUNO','ALMUERZO','MERIENDA','PAUSA','OTRO'],
        ];
    }

    public function listar(int $entrenadorId): array
    {
        $this->validarEntrenador($entrenadorId);

        return DB::table('gimnasio.entrenador_horarios')
            ->where('entrenador_id', $entrenadorId)
            ->orderByDesc('fecha_inicio')
            ->get()
            ->map(fn ($horario) => $this->hidratar($horario))
            ->all();
    }

    public function guardar(int $entrenadorId, array $datos, ?int $id = null): array
    {
        $this->validarEntrenador($entrenadorId);
        $this->validarFranjas($datos['franjas'] ?? [], $datos['recesos'] ?? []);
        $this->validarVigencia($entrenadorId, $datos['fecha_inicio'], $datos['fecha_fin'] ?? null, $id);

        return DB::transaction(function () use ($entrenadorId, $datos, $id): array {
            $antes = $id ? DB::table('gimnasio.entrenador_horarios')->where('id', $id)->where('entrenador_id', $entrenadorId)->first() : null;

            $cabecera = [
                'entrenador_id' => $entrenadorId,
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'activo' => $datos['activo'] ?? true,
                'observaciones' => $datos['observaciones'] ?? null,
                'updated_at' => now(),
            ];

            if ($id) {
                if (! $antes) {
                    throw ValidationException::withMessages(['horario' => 'La configuración de horario no existe.']);
                }
                DB::table('gimnasio.entrenador_horarios')->where('id', $id)->update($cabecera);
                $horarioId = $id;
            } else {
                $cabecera['created_at'] = now();
                $horarioId = DB::table('gimnasio.entrenador_horarios')->insertGetId($cabecera);
            }

            DB::table('gimnasio.entrenador_horario_franjas')->where('entrenador_horario_id', $horarioId)->delete();
            foreach ($datos['franjas'] as $franja) {
                DB::table('gimnasio.entrenador_horario_franjas')->insert([
                    'entrenador_horario_id' => $horarioId,
                    'sede_id' => (int) $franja['sede_id'],
                    'dia_semana' => $franja['dia_semana'],
                    'hora_inicio' => $franja['hora_inicio'],
                    'hora_fin' => $franja['hora_fin'],
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('gimnasio.entrenador_horario_recesos')->where('entrenador_horario_id', $horarioId)->delete();
            foreach (($datos['recesos'] ?? []) as $receso) {
                DB::table('gimnasio.entrenador_horario_recesos')->insert([
                    'entrenador_horario_id' => $horarioId,
                    'dia_semana' => $receso['dia_semana'],
                    'tipo' => $receso['tipo'] ?? 'PAUSA',
                    'descripcion' => $receso['descripcion'] ?? null,
                    'hora_inicio' => $receso['hora_inicio'],
                    'hora_fin' => $receso['hora_fin'],
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $despues = DB::table('gimnasio.entrenador_horarios')->where('id', $horarioId)->first();
            $this->auditar('gimnasio', $id ? 'ACTUALIZAR' : 'CREAR', 'gimnasio.entrenador_horarios', $horarioId, $antes, $despues);

            return $this->hidratar($despues);
        });
    }

    private function hidratar(object $horario): array
    {
        $franjas = DB::table('gimnasio.entrenador_horario_franjas as f')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'f.sede_id')
            ->where('f.entrenador_horario_id', $horario->id)
            ->where('f.activo', true)
            ->get(['f.id','f.sede_id','s.nombre as sede_nombre','f.dia_semana','f.hora_inicio','f.hora_fin'])
            ->sortBy(fn ($x) => (self::ORDEN_DIAS[$x->dia_semana] ?? 99).sprintf('%05d', str_replace(':','',substr((string)$x->hora_inicio,0,5))))
            ->values();

        $recesos = DB::table('gimnasio.entrenador_horario_recesos')
            ->where('entrenador_horario_id', $horario->id)
            ->where('activo', true)
            ->orderBy('dia_semana')
            ->orderBy('hora_inicio')
            ->get(['id','dia_semana','tipo','descripcion','hora_inicio','hora_fin']);

        return [
            'id' => $horario->id,
            'entrenador_id' => $horario->entrenador_id,
            'fecha_inicio' => $horario->fecha_inicio,
            'fecha_fin' => $horario->fecha_fin,
            'activo' => (bool) $horario->activo,
            'observaciones' => $horario->observaciones,
            'franjas' => $franjas->all(),
            'recesos' => $recesos->all(),
        ];
    }

    private function validarEntrenador(int $id): void
    {
        if (! DB::table('gimnasio.entrenadores')->where('id', $id)->where('estado', 'ACTIVO')->exists()) {
            throw ValidationException::withMessages(['entrenador_id' => 'El entrenador no existe o está inactivo.']);
        }
    }

    private function validarVigencia(int $entrenadorId, string $inicio, ?string $fin, ?int $ignorarId): void
    {
        $hasta = $fin ?: '9999-12-31';
        $cruce = DB::table('gimnasio.entrenador_horarios')
            ->where('entrenador_id', $entrenadorId)
            ->where('activo', true)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->whereDate('fecha_inicio', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $inicio))
            ->exists();

        if ($cruce) {
            throw ValidationException::withMessages(['fecha_inicio' => 'La vigencia se cruza con otra configuración activa del entrenador.']);
        }
    }

    private function validarFranjas(array $franjas, array $recesos): void
    {
        if (empty($franjas)) {
            throw ValidationException::withMessages(['franjas' => 'Agregue al menos una franja de disponibilidad.']);
        }

        foreach ($franjas as $i => $franja) {
            if (($franja['hora_inicio'] ?? '') >= ($franja['hora_fin'] ?? '')) {
                throw ValidationException::withMessages(["franjas.$i.hora_fin" => 'La hora final debe ser mayor que la inicial.']);
            }
            $sedeActiva = DB::table('institucional.sedes')->where('id_sede', (int)($franja['sede_id'] ?? 0))->where('activo', true)->exists();
            if (! $sedeActiva) {
                throw ValidationException::withMessages(["franjas.$i.sede_id" => 'La sede seleccionada no está activa.']);
            }
        }

        foreach ($franjas as $i => $a) {
            foreach ($franjas as $j => $b) {
                if ($j <= $i || $a['dia_semana'] !== $b['dia_semana']) continue;
                if ($a['hora_inicio'] < $b['hora_fin'] && $a['hora_fin'] > $b['hora_inicio']) {
                    throw ValidationException::withMessages(['franjas' => "Existen franjas superpuestas el {$a['dia_semana']}."]);
                }
            }
        }

        foreach ($recesos as $i => $receso) {
            if (($receso['hora_inicio'] ?? '') >= ($receso['hora_fin'] ?? '')) {
                throw ValidationException::withMessages(["recesos.$i.hora_fin" => 'La hora final del receso debe ser mayor que la inicial.']);
            }
            $contenido = collect($franjas)->contains(fn ($f) =>
                $f['dia_semana'] === $receso['dia_semana']
                && $receso['hora_inicio'] >= $f['hora_inicio']
                && $receso['hora_fin'] <= $f['hora_fin']
            );
            if (! $contenido) {
                throw ValidationException::withMessages(["recesos.$i" => 'Cada receso debe estar contenido dentro de una franja del mismo día.']);
            }
        }
    }
}
