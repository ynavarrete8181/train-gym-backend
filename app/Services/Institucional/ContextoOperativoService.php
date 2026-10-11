<?php

namespace App\Services\Institucional;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContextoOperativoService
{
    public function asegurarContextosSede(): void
    {
        $sedesActivas = DB::table('institucional.sedes')
            ->where('activo', true)
            ->pluck('id_sede')
            ->map(fn ($id) => (int) $id);

        DB::table('institucional.contextos')
            ->whereNull('id_unidad')
            ->whereNull('id_carrera_area')
            ->whereNotIn('id_sede', $sedesActivas->all())
            ->update(['activo' => false, 'updated_at' => now()]);

        foreach ($sedesActivas as $idSede) {
            DB::table('institucional.contextos')->updateOrInsert(
                ['id_sede' => $idSede, 'id_unidad' => null, 'id_carrera_area' => null],
                ['activo' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function contextosSede(): array
    {
        $this->asegurarContextosSede();

        return DB::table('institucional.contextos as x')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'x.id_sede')
            ->whereNull('x.id_unidad')
            ->whereNull('x.id_carrera_area')
            ->where('x.activo', true)
            ->where('s.activo', true)
            ->select([
                'x.id_contexto', 's.id_sede', 's.nombre as sede_nombre',
                DB::raw('NULL::bigint as id_unidad'), DB::raw('NULL::varchar as unidad_nombre'), DB::raw('NULL::varchar as unidad_tipo'),
                DB::raw('NULL::bigint as id_carrera_area'), DB::raw('NULL::varchar as carrera_area_nombre'), DB::raw('NULL::varchar as carrera_area_tipo'),
                DB::raw("s.codigo || ' | Toda la sede' as nombre"), DB::raw("'SEDE'::varchar as nivel"),
            ])
            ->orderBy('s.nombre')
            ->get()
            ->map(fn ($item) => (array) $item)
            ->all();
    }

    public function validarContextos(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) return [];

        $contextos = DB::table('institucional.contextos')
            ->whereIn('id_contexto', $ids)
            ->where('activo', true)
            ->get(['id_contexto', 'id_sede', 'id_unidad', 'id_carrera_area']);

        if ($contextos->count() !== count($ids)) {
            throw ValidationException::withMessages(['contextos' => 'Una o varias asignaciones operativas no existen o están inactivas.']);
        }

        foreach ($contextos->groupBy('id_sede') as $grupoSede) {
            if ($grupoSede->contains(fn ($c) => $c->id_unidad === null) && $grupoSede->count() > 1) {
                throw ValidationException::withMessages(['contextos' => 'No combines acceso a toda una sede con áreas o líneas de esa misma sede.']);
            }

            foreach ($grupoSede->whereNotNull('id_unidad')->groupBy('id_unidad') as $grupoUnidad) {
                if ($grupoUnidad->contains(fn ($c) => $c->id_carrera_area === null) && $grupoUnidad->count() > 1) {
                    throw ValidationException::withMessages(['contextos' => 'No combines acceso a toda un área con líneas específicas de esa misma área.']);
                }
            }
        }

        return $ids;
    }
}
