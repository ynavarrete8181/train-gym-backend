<?php

namespace App\Services\Institucional;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EstructuraInstitucionalService
{
    use RegistraAuditoria;

    public function catalogos(): array
    {
        $sedes = DB::table('institucional.sedes')->orderBy('nombre')->get();
        $unidades = DB::table('institucional.sede_unidad as su')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')
            ->select(
                'su.id as id_sede_unidad', 'su.codigo', 'su.id_sede', 'su.id_unidad', 'su.activo as relacion_activa',
                'u.nombre', 'u.tipo', 'u.activo', 's.nombre as sede_nombre', 's.codigo as sede_codigo'
            )
            ->orderBy('u.nombre')->orderBy('s.nombre')->get();
        $unidades = $this->adjuntarAliases($unidades, 'unidades_aliases', 'id_unidad');

        $carrerasAreas = DB::table('institucional.carreras_areas as c')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'c.id_unidad')
            ->leftJoin('institucional.sede_unidad as su', 'su.id', '=', 'c.id_sede_unidad')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')
            ->leftJoin('institucional.campos_amplios as ca', 'ca.id_campo_amplio', '=', 'c.id_campo_amplio')
            ->select(
                'c.*', 'u.nombre as unidad_nombre', 'u.tipo as unidad_tipo',
                'su.id_sede', 'su.codigo as unidad_sede_codigo', 's.nombre as sede_nombre',
                'ca.nombre as campo_amplio_nombre'
            )
            ->orderBy('c.nombre')->orderBy('s.nombre')->get();

        return [
            'sedes' => $this->adjuntarAliases($sedes, 'sedes_aliases', 'id_sede'),
            'unidades' => $unidades,
            'carreras_areas' => $this->adjuntarAliases($carrerasAreas, 'carreras_areas_aliases', 'id_carrera_area'),
            'campos_amplios' => DB::table('institucional.campos_amplios')->orderBy('nombre')->get(),
            'contextos' => $this->contextos(),
        ];
    }

    public function guardarSede(array $datos, ?int $id = null): object
    {
        return DB::transaction(function () use ($datos, $id) {
            $esNueva = ! $id;
            $antes = $id ? DB::table('institucional.sedes')->where('id_sede', $id)->first() : null;
            $aliases = $datos['aliases'] ?? [];
            unset($datos['aliases']);
            $codigoAnterior = $id ? $this->codigoExistente('sedes', 'id_sede', $id) : null;
            $datos['codigo'] = $this->generarCodigo('SEDE', $datos['nombre'], 200, 'sedes', 'id_sede', $id);
            $aliases = $this->conservarCodigoAnterior($aliases, $codigoAnterior, $datos['codigo']);
            $datos['updated_at'] = now();
            if ($id) DB::table('institucional.sedes')->where('id_sede', $id)->update($datos);
            else { $datos['created_at'] = now(); $id = DB::table('institucional.sedes')->insertGetId($datos, 'id_sede'); }
            $this->sincronizarAliases('sedes_aliases', 'id_sede', $id, $aliases, $datos['nombre']);
            $this->regenerarCodigosUnidad();
            $this->regenerarContextos();
            $despues = DB::table('institucional.sedes')->where('id_sede', $id)->first();
            $this->auditar('institucional', $esNueva ? 'CREAR' : 'ACTUALIZAR', 'institucional.sedes', $id, $antes, $despues);
            return $despues;
        });
    }

    public function guardarUnidad(array $datos, ?int $idRelacion = null): object
    {
        return DB::transaction(function () use ($datos, $idRelacion) {
            $aliases = $datos['aliases'] ?? [];
            $idSede = (int) $datos['id_sede'];
            $tipo = mb_strtoupper($datos['tipo']);
            $nombre = trim($datos['nombre']);
            $activo = (bool) $datos['activo'];

            $relacionActual = $idRelacion ? DB::table('institucional.sede_unidad')->where('id', $idRelacion)->first() : null;
            if ($idRelacion && ! $relacionActual) abort(404);

            $idUnidad = $relacionActual?->id_unidad;
            if (! $idUnidad) {
                $idUnidad = DB::table('institucional.unidades')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->where('tipo', $tipo)->value('id_unidad');
            }

            if ($idUnidad) {
                DB::table('institucional.unidades')->where('id_unidad', $idUnidad)->update([
                    'nombre' => $nombre, 'tipo' => $tipo, 'activo' => $activo, 'updated_at' => now(),
                ]);
            } else {
                $codigoBase = $this->generarCodigo($tipo, $nombre, 200, 'unidades', 'id_unidad');
                $idUnidad = DB::table('institucional.unidades')->insertGetId([
                    'codigo' => $codigoBase, 'nombre' => $nombre, 'tipo' => $tipo, 'activo' => $activo,
                    'created_at' => now(), 'updated_at' => now(),
                ], 'id_unidad');
            }

            $duplicado = DB::table('institucional.sede_unidad')->where('id_sede', $idSede)->where('id_unidad', $idUnidad);
            if ($idRelacion) $duplicado->where('id', '<>', $idRelacion);
            if ($duplicado->exists()) {
                throw ValidationException::withMessages(['id_sede' => 'Esta facultad o dirección ya está registrada en la sede seleccionada.']);
            }

            $codigoRelacion = $this->codigoUnidadSede($tipo, $nombre, $idSede, $idRelacion);
            if ($idRelacion) {
                DB::table('institucional.sede_unidad')->where('id', $idRelacion)->update([
                    'id_sede' => $idSede, 'id_unidad' => $idUnidad, 'codigo' => $codigoRelacion, 'activo' => $activo, 'updated_at' => now(),
                ]);
            } else {
                $idRelacion = DB::table('institucional.sede_unidad')->insertGetId([
                    'id_sede' => $idSede, 'id_unidad' => $idUnidad, 'codigo' => $codigoRelacion, 'activo' => $activo,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->sincronizarAliases('unidades_aliases', 'id_unidad', $idUnidad, $aliases, $nombre);
            $this->regenerarCodigosUnidad($idUnidad);
            $this->regenerarContextos();

            $despues = DB::table('institucional.sede_unidad as su')
                ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
                ->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')
                ->select('su.id as id_sede_unidad', 'su.codigo', 'su.id_sede', 'u.*', 's.nombre as sede_nombre')
                ->where('su.id', $idRelacion)->first();
            $this->auditar('institucional', $relacionActual ? 'ACTUALIZAR' : 'CREAR', 'institucional.sede_unidad', $idRelacion, $relacionActual, $despues);
            return $despues;
        });
    }

    public function guardarCarreraArea(array $datos, ?int $id = null): object
    {
        return DB::transaction(function () use ($datos, $id) {
            $aliases = $datos['aliases'] ?? [];
            unset($datos['aliases']);
            $relacion = DB::table('institucional.sede_unidad as su')
                ->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
                ->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')
                ->select('su.id', 'su.id_unidad', 'su.id_sede', 'u.tipo as unidad_tipo', 's.codigo as sede_codigo')
                ->where('su.id', $datos['id_sede_unidad'])->first();
            if (! $relacion || ($datos['tipo'] === 'CARRERA' && $relacion->unidad_tipo !== 'FACULTAD') || ($datos['tipo'] === 'AREA' && $relacion->unidad_tipo !== 'DIRECCION')) {
                throw ValidationException::withMessages(['id_sede_unidad' => 'Carrera requiere Facultad y Área requiere Dirección en una sede válida.']);
            }

            $datos['id_unidad'] = $relacion->id_unidad;
            $codigoAnterior = $id ? $this->codigoExistente('carreras_areas', 'id_carrera_area', $id) : null;
            $datos['codigo'] = $this->codigoCarreraSede($datos['tipo'], $datos['nombre'], $relacion->sede_codigo, $id);
            $aliases = $this->conservarCodigoAnterior($aliases, $codigoAnterior, $datos['codigo']);

            $duplicado = DB::table('institucional.carreras_areas')
                ->where('id_sede_unidad', $datos['id_sede_unidad'])
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($datos['nombre']))]);
            if ($id) $duplicado->where('id_carrera_area', '<>', $id);
            if ($duplicado->exists()) {
                throw ValidationException::withMessages(['nombre' => 'Ya existe esta carrera o área en la sede y estructura seleccionadas.']);
            }

            $esNueva = ! $id;
            $antesCarrera = $id ? DB::table('institucional.carreras_areas')->where('id_carrera_area', $id)->first() : null;
            $datos['updated_at'] = now();
            if ($id) DB::table('institucional.carreras_areas')->where('id_carrera_area', $id)->update($datos);
            else { $datos['created_at'] = now(); $id = DB::table('institucional.carreras_areas')->insertGetId($datos, 'id_carrera_area'); }
            $this->sincronizarAliases('carreras_areas_aliases', 'id_carrera_area', $id, $aliases, $datos['nombre']);
            $this->regenerarContextos();
            $despues = DB::table('institucional.carreras_areas')->where('id_carrera_area', $id)->first();
            $this->auditar('institucional', $esNueva ? 'CREAR' : 'ACTUALIZAR', 'institucional.carreras_areas', $id, $antesCarrera, $despues);
            return $despues;
        });
    }

    public function cambiarEstado(string $tipo, int $id, bool $activo): void
    {
        if ($tipo === 'unidades') {
            $relacion = DB::table('institucional.sede_unidad')->where('id', $id)->first();
            if (! $relacion) abort(404);
            DB::table('institucional.sede_unidad')->where('id', $id)->update(['activo' => $activo, 'updated_at' => now()]);
            $this->auditar('institucional', 'ACTUALIZAR', 'institucional.sede_unidad', $id, null, null, 'Cambio de estado.');
        } else {
            $mapa = ['sedes' => ['sedes', 'id_sede'], 'carreras-areas' => ['carreras_areas', 'id_carrera_area']];
            if (! isset($mapa[$tipo])) abort(404);
            [$tabla, $pk] = $mapa[$tipo];
            DB::table("institucional.$tabla")->where($pk, $id)->update(['activo' => $activo, 'updated_at' => now()]);
            $this->auditar('institucional', 'ACTUALIZAR', "institucional.$tabla", $id, null, null, 'Cambio de estado.');
        }
        $this->regenerarContextos();
    }

    public function asignarUsuario(int $usuario, array $contextos): void
    {
        DB::transaction(function () use ($usuario, $contextos) {
            DB::table('institucional.usuario_contexto')->where('id_usuario', $usuario)->delete();
            foreach (array_values(array_unique($contextos)) as $indice => $id) DB::table('institucional.usuario_contexto')->insert([
                'id_usuario' => $usuario, 'id_contexto' => $id, 'principal' => $indice === 0, 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function contextosUsuario(int $usuario): array
    {
        return DB::table('institucional.usuario_contexto')->where('id_usuario', $usuario)->where('activo', true)
            ->orderByDesc('principal')->pluck('id_contexto')->map(fn ($v) => (int) $v)->all();
    }

    public function contextos(): array
    {
        return DB::table('institucional.contextos as x')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'x.id_sede')
            ->join('institucional.unidades as u', 'u.id_unidad', '=', 'x.id_unidad')
            ->leftJoin('institucional.carreras_areas as c', 'c.id_carrera_area', '=', 'x.id_carrera_area')
            ->where('x.activo', true)
            ->select('x.id_contexto', 's.id_sede', 's.nombre as sede_nombre', 'u.id_unidad', 'u.nombre as unidad_nombre', 'u.tipo as unidad_tipo',
                'c.id_carrera_area', 'c.nombre as carrera_area_nombre', 'c.tipo as carrera_area_tipo',
                DB::raw("s.codigo || ' | ' || u.nombre || COALESCE(' | ' || c.nombre, '') as nombre"))
            ->orderBy('nombre')->get()->map(fn ($v) => (array) $v)->all();
    }

    private function adjuntarAliases(Collection $registros, string $tabla, string $fk): Collection
    {
        $aliases = DB::table("institucional.$tabla")->select($fk, 'alias')->orderBy('alias')->get()->groupBy($fk);
        return $registros->map(function ($registro) use ($aliases, $fk) {
            $registro->aliases = $aliases->get($registro->{$fk}, collect())->pluck('alias')->values()->all();
            return $registro;
        });
    }

    private function sincronizarAliases(string $tabla, string $fk, int $id, array $aliases, string $nombreOficial): void
    {
        $nombreNormalizado = $this->normalizarAlias($nombreOficial);
        $preparados = collect($aliases)->map(fn ($a) => trim($a))->filter()->map(fn ($a) => ['alias' => $a, 'normalizado' => $this->normalizarAlias($a)])
            ->filter(fn ($a) => $a['normalizado'] !== $nombreNormalizado);
        if ($preparados->pluck('normalizado')->unique()->count() !== $preparados->count()) throw ValidationException::withMessages(['aliases' => 'Existen alias repetidos después de normalizarlos.']);
        [$tablaCatalogo, $pkCatalogo] = match ($tabla) { 'sedes_aliases' => ['sedes', 'id_sede'], 'unidades_aliases' => ['unidades', 'id_unidad'], 'carreras_areas_aliases' => ['carreras_areas', 'id_carrera_area'] };
        $nombresOcupados = DB::table("institucional.$tablaCatalogo")->where($pkCatalogo, '<>', $id)->pluck('nombre')->map(fn ($n) => $this->normalizarAlias($n));
        foreach ($preparados as $alias) {
            if ($nombresOcupados->contains($alias['normalizado'])) throw ValidationException::withMessages(['aliases' => "El alias {$alias['alias']} coincide con el nombre oficial de otro registro."]);
            if (DB::table("institucional.$tabla")->where('alias_normalizado', $alias['normalizado'])->where($fk, '<>', $id)->exists()) throw ValidationException::withMessages(['aliases' => "El alias {$alias['alias']} ya pertenece a otro registro."]);
        }
        DB::table("institucional.$tabla")->where($fk, $id)->delete();
        foreach ($preparados as $alias) DB::table("institucional.$tabla")->insert([$fk => $id, 'alias' => $alias['alias'], 'alias_normalizado' => $alias['normalizado'], 'created_at' => now(), 'updated_at' => now()]);
    }

    private function normalizarAlias(string $alias): string
    {
        return Str::of(Str::ascii($alias))->upper()->replaceMatches('/[^A-Z0-9]+/', ' ')->squish()->toString();
    }

    private function codigoExistente(string $tabla, string $pk, int $id): string
    {
        $codigo = DB::table("institucional.$tabla")->where($pk, $id)->value('codigo');
        if (! $codigo) abort(404);
        return $codigo;
    }

    private function conservarCodigoAnterior(array $aliases, ?string $anterior, string $nuevo): array
    {
        if (! $anterior || $anterior === $nuevo) return $aliases;
        $normalizado = $this->normalizarAlias($anterior);
        if (! collect($aliases)->contains(fn ($a) => $this->normalizarAlias($a) === $normalizado)) $aliases[] = $anterior;
        return $aliases;
    }

    private function generarCodigo(string $prefijo, string $nombre, int $maximo, string $tabla, string $pk, ?int $id = null): string
    {
        $base = Str::of(Str::ascii($nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
        $base = preg_replace('/^'.preg_quote($prefijo, '/').'_/', '', $base);
        $codigo = rtrim(substr($prefijo.'_'.$base, 0, $maximo), '_');
        $q = DB::table("institucional.$tabla")->where('codigo', $codigo); if ($id) $q->where($pk, '<>', $id);
        if ($q->exists()) throw ValidationException::withMessages(['nombre' => 'Ya existe un registro que genera el código '.$codigo.'.']);
        return $codigo;
    }

    private function codigoUnidadSede(string $tipo, string $nombre, int $idSede, ?int $idRelacion = null): string
    {
        $sede = DB::table('institucional.sedes')->where('id_sede', $idSede)->value('codigo');
        if (! $sede) throw ValidationException::withMessages(['id_sede' => 'La sede seleccionada no existe.']);
        $base = Str::of(Str::ascii($nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
        $codigo = substr($tipo.'_'.Str::of($sede)->replace('SEDE_', '').'_'.$base, 0, 200);
        $q = DB::table('institucional.sede_unidad')->where('codigo', $codigo); if ($idRelacion) $q->where('id', '<>', $idRelacion);
        if ($q->exists()) throw ValidationException::withMessages(['nombre' => 'Ya existe una unidad con el código '.$codigo.'.']);
        return $codigo;
    }

    private function codigoCarreraSede(string $tipo, string $nombre, string $sedeCodigo, ?int $id = null): string
    {
        $base = Str::of(Str::ascii($nombre))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
        $sede = Str::of($sedeCodigo)->replace('SEDE_', '')->toString();
        $codigo = substr($tipo.'_'.$sede.'_'.$base, 0, 200);
        $q = DB::table('institucional.carreras_areas')->where('codigo', $codigo); if ($id) $q->where('id_carrera_area', '<>', $id);
        if ($q->exists()) throw ValidationException::withMessages(['nombre' => 'Ya existe una carrera o área con el código '.$codigo.'.']);
        return $codigo;
    }

    private function regenerarCodigosUnidad(?int $idUnidad = null): void
    {
        $q = DB::table('institucional.sede_unidad as su')->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->select('su.id', 'su.id_sede', 'u.tipo', 'u.nombre');
        if ($idUnidad) $q->where('su.id_unidad', $idUnidad);
        foreach ($q->get() as $r) DB::table('institucional.sede_unidad')->where('id', $r->id)->update(['codigo' => $this->codigoUnidadSede($r->tipo, $r->nombre, $r->id_sede, $r->id), 'updated_at' => now()]);
    }

    private function regenerarContextos(): void
    {
        DB::table('institucional.contextos')->update(['activo' => false, 'updated_at' => now()]);
        $relaciones = DB::table('institucional.sede_unidad as su')->join('institucional.sedes as s', 's.id_sede', '=', 'su.id_sede')->join('institucional.unidades as u', 'u.id_unidad', '=', 'su.id_unidad')
            ->where('su.activo', true)->where('s.activo', true)->where('u.activo', true)->select('su.id', 'su.id_sede', 'su.id_unidad')->get();
        foreach ($relaciones as $relacion) {
            $hijos = collect([null])->merge(DB::table('institucional.carreras_areas')->where('id_unidad', $relacion->id_unidad)->where('activo', true)
                ->where(fn ($q) => $q->whereNull('id_sede_unidad')->orWhere('id_sede_unidad', $relacion->id))->pluck('id_carrera_area'));
            foreach ($hijos as $hijo) DB::table('institucional.contextos')->updateOrInsert(
                ['id_sede' => $relacion->id_sede, 'id_unidad' => $relacion->id_unidad, 'id_carrera_area' => $hijo],
                ['activo' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
