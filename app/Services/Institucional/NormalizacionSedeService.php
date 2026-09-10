<?php

namespace App\Services\Institucional;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NormalizacionSedeService
{
    public function normalizarManta(): void
    {
        DB::transaction(function (): void {
            $manta = DB::table('institucional.sedes')
                ->whereRaw('LOWER(TRIM(nombre)) = ?', ['manta'])
                ->first();

            $incorrectas = DB::table('institucional.sedes')
                ->whereRaw("LOWER(TRIM(nombre)) IN ('matriz - manta', 'matriz-manta', 'matriz manta')")
                ->orderBy('id_sede')
                ->get();

            if (! $manta && $incorrectas->isNotEmpty()) {
                $principal = $incorrectas->shift();
                DB::table('institucional.sedes')->where('id_sede', $principal->id_sede)->update([
                    'nombre' => 'Manta',
                    'codigo' => 'SEDE_MANTA',
                    'updated_at' => now(),
                ]);
                $manta = DB::table('institucional.sedes')->where('id_sede', $principal->id_sede)->first();
            }

            if (! $manta) {
                return;
            }

            foreach ($incorrectas as $incorrecta) {
                if ((int) $incorrecta->id_sede === (int) $manta->id_sede) {
                    continue;
                }

                $this->fusionarRelaciones((int) $incorrecta->id_sede, (int) $manta->id_sede);
                $this->fusionarContextosSede((int) $incorrecta->id_sede, (int) $manta->id_sede);

                DB::table('institucional.sedes_aliases')->where('id_sede', $incorrecta->id_sede)->delete();
                DB::table('institucional.sedes')->where('id_sede', $incorrecta->id_sede)->delete();
            }

            $this->fusionarRelacionesDuplicadasEnSede((int) $manta->id_sede);
            $this->normalizarCodigos((int) $manta->id_sede);
        });
    }

    private function fusionarRelaciones(int $origen, int $destino): void
    {
        foreach (DB::table('institucional.sede_unidad')->where('id_sede', $origen)->get() as $relacion) {
            $equivalente = DB::table('institucional.sede_unidad')
                ->where('id_sede', $destino)
                ->where('id_unidad', $relacion->id_unidad)
                ->first();

            if ($equivalente) {
                $this->moverCarreras((int) $relacion->id, (int) $equivalente->id, (int) $equivalente->id_unidad, $destino);
                DB::table('institucional.sede_unidad')->where('id', $relacion->id)->delete();
            } else {
                DB::table('institucional.sede_unidad')->where('id', $relacion->id)->update([
                    'id_sede' => $destino,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function fusionarRelacionesDuplicadasEnSede(int $idSede): void
    {
        $relaciones = DB::table('institucional.sede_unidad as su')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->where('su.id_sede', $idSede)
            ->select('su.id', 'su.id_unidad', 'u.tipo', 'u.nombre')
            ->orderBy('su.id')
            ->get();

        $grupos = $relaciones->groupBy(fn ($item) => mb_strtoupper($item->tipo).'|'.$this->codigo($item->nombre));

        foreach ($grupos as $grupo) {
            if ($grupo->count() < 2) {
                continue;
            }

            $principal = $grupo->first();

            foreach ($grupo->slice(1) as $duplicada) {
                $this->moverCarreras((int) $duplicada->id, (int) $principal->id, (int) $principal->id_unidad, $idSede);
                $this->fusionarContextosUnidad($idSede, (int) $duplicada->id_unidad, (int) $principal->id_unidad);
                DB::table('institucional.sede_unidad')->where('id', $duplicada->id)->delete();

                $tieneOtrasRelaciones = DB::table('institucional.sede_unidad')
                    ->where('id_unidad', $duplicada->id_unidad)
                    ->exists();

                if (! $tieneOtrasRelaciones) {
                    DB::table('institucional.unidades_aliases')->where('id_unidad', $duplicada->id_unidad)->delete();
                    DB::table('institucional.unidades')->where('id_unidad', $duplicada->id_unidad)->delete();
                }
            }
        }
    }

    private function moverCarreras(int $relacionOrigen, int $relacionDestino, int $unidadDestino, int $idSede): void
    {
        foreach (DB::table('institucional.carreras_areas')->where('id_sede_unidad', $relacionOrigen)->get() as $carrera) {
            $equivalente = DB::table('institucional.carreras_areas')
                ->where('id_sede_unidad', $relacionDestino)
                ->where('tipo', $carrera->tipo)
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($carrera->nombre))])
                ->first();

            if ($equivalente) {
                $this->fusionarContextosCarrera($idSede, (int) $carrera->id_carrera_area, (int) $equivalente->id_carrera_area, $unidadDestino);
                DB::table('institucional.carreras_areas_aliases')->where('id_carrera_area', $carrera->id_carrera_area)->delete();
                DB::table('institucional.carreras_areas')->where('id_carrera_area', $carrera->id_carrera_area)->delete();
                continue;
            }

            DB::table('institucional.carreras_areas')->where('id_carrera_area', $carrera->id_carrera_area)->update([
                'id_sede_unidad' => $relacionDestino,
                'id_unidad' => $unidadDestino,
                'updated_at' => now(),
            ]);
        }
    }

    private function fusionarContextosSede(int $origen, int $destino): void
    {
        foreach (DB::table('institucional.contextos')->where('id_sede', $origen)->get() as $contexto) {
            $consulta = DB::table('institucional.contextos')
                ->where('id_sede', $destino)
                ->where('id_unidad', $contexto->id_unidad);

            $contexto->id_carrera_area === null
                ? $consulta->whereNull('id_carrera_area')
                : $consulta->where('id_carrera_area', $contexto->id_carrera_area);

            $equivalente = $consulta->first();

            if (! $equivalente) {
                DB::table('institucional.contextos')->where('id_contexto', $contexto->id_contexto)->update([
                    'id_sede' => $destino,
                    'updated_at' => now(),
                ]);
                continue;
            }

            $this->moverAsignacionesContexto((int) $contexto->id_contexto, (int) $equivalente->id_contexto);
            DB::table('institucional.contextos')->where('id_contexto', $contexto->id_contexto)->delete();
        }
    }

    private function fusionarContextosUnidad(int $idSede, int $unidadOrigen, int $unidadDestino): void
    {
        foreach (DB::table('institucional.contextos')->where('id_sede', $idSede)->where('id_unidad', $unidadOrigen)->get() as $contexto) {
            $consulta = DB::table('institucional.contextos')
                ->where('id_sede', $idSede)
                ->where('id_unidad', $unidadDestino);

            $contexto->id_carrera_area === null
                ? $consulta->whereNull('id_carrera_area')
                : $consulta->where('id_carrera_area', $contexto->id_carrera_area);

            $equivalente = $consulta->first();

            if ($equivalente) {
                $this->moverAsignacionesContexto((int) $contexto->id_contexto, (int) $equivalente->id_contexto);
                DB::table('institucional.contextos')->where('id_contexto', $contexto->id_contexto)->delete();
            } else {
                DB::table('institucional.contextos')->where('id_contexto', $contexto->id_contexto)->update([
                    'id_unidad' => $unidadDestino,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function fusionarContextosCarrera(int $idSede, int $carreraOrigen, int $carreraDestino, int $unidadDestino): void
    {
        foreach (DB::table('institucional.contextos')->where('id_sede', $idSede)->where('id_carrera_area', $carreraOrigen)->get() as $contexto) {
            $equivalente = DB::table('institucional.contextos')
                ->where('id_sede', $idSede)
                ->where('id_unidad', $unidadDestino)
                ->where('id_carrera_area', $carreraDestino)
                ->first();

            if ($equivalente) {
                $this->moverAsignacionesContexto((int) $contexto->id_contexto, (int) $equivalente->id_contexto);
                DB::table('institucional.contextos')->where('id_contexto', $contexto->id_contexto)->delete();
            } else {
                DB::table('institucional.contextos')->where('id_contexto', $contexto->id_contexto)->update([
                    'id_unidad' => $unidadDestino,
                    'id_carrera_area' => $carreraDestino,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function moverAsignacionesContexto(int $origen, int $destino): void
    {
        foreach (DB::table('institucional.usuario_contexto')->where('id_contexto', $origen)->get() as $asignacion) {
            $existente = DB::table('institucional.usuario_contexto')
                ->where('id_usuario', $asignacion->id_usuario)
                ->where('id_contexto', $destino)
                ->first();

            if ($existente) {
                DB::table('institucional.usuario_contexto')->where('id', $existente->id)->update([
                    'principal' => (bool) $existente->principal || (bool) $asignacion->principal,
                    'activo' => (bool) $existente->activo || (bool) $asignacion->activo,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('institucional.usuario_contexto')->insert([
                    'id_usuario' => $asignacion->id_usuario,
                    'id_contexto' => $destino,
                    'principal' => (bool) $asignacion->principal,
                    'activo' => (bool) $asignacion->activo,
                    'created_at' => $asignacion->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('institucional.usuario_contexto')->where('id_contexto', $origen)->delete();
    }

    private function normalizarCodigos(int $idManta): void
    {
        DB::table('institucional.sedes')->where('id_sede', $idManta)->update([
            'nombre' => 'Manta',
            'codigo' => 'SEDE_MANTA',
            'updated_at' => now(),
        ]);

        foreach (DB::table('institucional.sede_unidad as su')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->where('su.id_sede', $idManta)
            ->select('su.id', 'u.tipo', 'u.nombre')
            ->get() as $relacion) {
            DB::table('institucional.sede_unidad')->where('id', $relacion->id)->update([
                'codigo' => substr($relacion->tipo.'_MANTA_'.$this->codigo($relacion->nombre), 0, 200),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('institucional.carreras_areas as c')
            ->join('institucional.sede_unidad as su', 'su.id', '=', 'c.id_sede_unidad')
            ->where('su.id_sede', $idManta)
            ->select('c.id_carrera_area', 'c.tipo', 'c.nombre')
            ->get() as $carrera) {
            DB::table('institucional.carreras_areas')->where('id_carrera_area', $carrera->id_carrera_area)->update([
                'codigo' => substr($carrera->tipo.'_MANTA_'.$this->codigo($carrera->nombre), 0, 200),
                'updated_at' => now(),
            ]);
        }
    }

    private function codigo(string $valor): string
    {
        return Str::of(Str::ascii($valor))
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }
}
