<?php

namespace App\Queries\Personas;

use Illuminate\Support\Facades\DB;

class MembresiaQuery
{
    public function listarCatalogo(?int $sedeId = null): array
    {
        $hasPreciosSede = $this->hasPreciosSedeTable();

        $query = DB::table('socios.membresias as m')
            ->selectRaw('
                m.id,
                m.nombre,
                m.descripcion,
                m.duracion_dias,
                m.precio as precio_base,
                m.activa,
                m.created_at,
                m.updated_at
            ');

        if ($sedeId && $hasPreciosSede) {
            $query->addSelect(DB::raw("
                COALESCE((
                    SELECT mps.precio
                    FROM socios.membresia_precios_sede mps
                    WHERE mps.membresia_id = m.id
                      AND mps.sede_id = {$sedeId}
                      AND mps.activa = TRUE
                      AND (mps.vigencia_inicio IS NULL OR mps.vigencia_inicio <= CURRENT_DATE)
                      AND (mps.vigencia_fin IS NULL OR mps.vigencia_fin >= CURRENT_DATE)
                    ORDER BY mps.vigencia_inicio DESC NULLS LAST, mps.id DESC
                    LIMIT 1
                ), m.precio) as precio,
                (
                    SELECT mps.precio
                    FROM socios.membresia_precios_sede mps
                    WHERE mps.membresia_id = m.id
                      AND mps.sede_id = {$sedeId}
                      AND mps.activa = TRUE
                      AND (mps.vigencia_inicio IS NULL OR mps.vigencia_inicio <= CURRENT_DATE)
                      AND (mps.vigencia_fin IS NULL OR mps.vigencia_fin >= CURRENT_DATE)
                    ORDER BY mps.vigencia_inicio DESC NULLS LAST, mps.id DESC
                    LIMIT 1
                ) as precio_sede
            "));
        } else {
            $query->addSelect(DB::raw('m.precio as precio, NULL as precio_sede'));
        }

        $items = $query
            ->orderByDesc('activa')
            ->orderBy('nombre')
            ->get();

        $preciosPorSede = collect();
        if ($hasPreciosSede && $items->isNotEmpty()) {
            $preciosPorSede = DB::table('socios.membresia_precios_sede as mps')
                ->join('core.sedes as s', 's.id', '=', 'mps.sede_id')
                ->select('mps.id', 'mps.membresia_id', 'mps.sede_id', 's.nombre as sede_nombre', 'mps.precio', 'mps.vigencia_inicio', 'mps.vigencia_fin', 'mps.activa')
                ->whereIn('mps.membresia_id', $items->pluck('id')->all())
                ->orderBy('s.nombre')
                ->orderByDesc('mps.activa')
                ->orderByDesc('mps.vigencia_inicio')
                ->get()
                ->groupBy('membresia_id');
        }

        return $items
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'nombre' => $item->nombre,
                'descripcion' => $item->descripcion,
                'duracion_dias' => (int) $item->duracion_dias,
                'precio' => (float) $item->precio,
                'precio_base' => (float) $item->precio_base,
                'precio_sede' => $item->precio_sede !== null ? (float) $item->precio_sede : null,
                'activa' => (bool) $item->activa,
                'precios_sede' => ($preciosPorSede->get($item->id) ?? collect())
                    ->map(fn ($precio) => [
                        'id' => (int) $precio->id,
                        'sede_id' => (int) $precio->sede_id,
                        'sede_nombre' => $precio->sede_nombre,
                        'precio' => (float) $precio->precio,
                        'vigencia_inicio' => $precio->vigencia_inicio,
                        'vigencia_fin' => $precio->vigencia_fin,
                        'activa' => (bool) $precio->activa,
                    ])
                    ->values()
                    ->all(),
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ])
            ->all();
    }

    private function hasPreciosSedeTable(): bool
    {
        $row = DB::selectOne("SELECT to_regclass('socios.membresia_precios_sede') as table_name");

        return !empty($row?->table_name);
    }

    public function listarAsignaciones(array $filtros = []): array
    {
        $query = DB::table('socios.socio_membresias as sm')
            ->join('socios.socios as s', 's.id', '=', 'sm.socio_id')
            ->join('core.personas as p', 'p.id', '=', 's.persona_id')
            ->join('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->leftJoin('core.sedes as se', 'se.id', '=', 'sm.sede_id')
            ->leftJoin('core.estados as e', 'e.id', '=', 'sm.estado_id')
            ->selectRaw("
                sm.id,
                sm.socio_id,
                sm.membresia_id,
                sm.sede_id,
                sm.fecha_inicio,
                sm.fecha_fin,
                sm.precio_aplicado,
                se.nombre as sede_nombre,
                s.codigo_socio,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos,
                m.nombre as membresia_nombre,
                m.duracion_dias,
                m.precio,
                e.codigo as estado_codigo,
                e.nombre as estado_nombre
            ");

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('s.codigo_socio', 'like', $buscar)
                    ->orWhere('p.numero_identificacion', 'like', $buscar)
                    ->orWhere('p.nombres', 'like', $buscar)
                    ->orWhere('p.apellidos', 'like', $buscar)
                    ->orWhere('m.nombre', 'like', $buscar);
            });
        }

        if (!empty($filtros['sede_id'])) {
            $query->where('sm.sede_id', (int) $filtros['sede_id']);
        }

        if (!empty($filtros['membresia_id'])) {
            $query->where('sm.membresia_id', (int) $filtros['membresia_id']);
        }

        return $query
            ->orderByDesc('sm.fecha_fin')
            ->orderBy('p.nombres')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'socio_id' => (int) $item->socio_id,
                'membresia_id' => (int) $item->membresia_id,
                'sede_id' => $item->sede_id ? (int) $item->sede_id : null,
                'sede_nombre' => $item->sede_nombre,
                'fecha_inicio' => $item->fecha_inicio,
                'fecha_fin' => $item->fecha_fin,
                'codigo_socio' => $item->codigo_socio,
                'cedula' => $item->cedula,
                'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
                'membresia_nombre' => $item->membresia_nombre,
                'duracion_dias' => (int) $item->duracion_dias,
                'precio' => (float) ($item->precio_aplicado ?? $item->precio),
                'precio_base' => (float) $item->precio,
                'estado_codigo' => $item->estado_codigo ?? 'ACTIVO',
                'estado_nombre' => $item->estado_nombre ?? 'Activo',
            ])
            ->all();
    }

    public function listarSociosDisponibles(): array
    {
        return DB::table('socios.socios as s')
            ->join('core.personas as p', 'p.id', '=', 's.persona_id')
            ->leftJoin('core.estados as e', 'e.id', '=', 's.estado_id')
            ->leftJoin('socios.socio_membresias as sm', function ($join) {
                $join->on('sm.socio_id', '=', 's.id')
                    ->whereRaw('sm.id = (
                        SELECT sm2.id
                        FROM socios.socio_membresias sm2
                        WHERE sm2.socio_id = s.id
                        ORDER BY sm2.fecha_fin DESC, sm2.id DESC
                        LIMIT 1
                    )');
            })
            ->leftJoin('socios.membresias as m', 'm.id', '=', 'sm.membresia_id')
            ->selectRaw("
                s.id,
                s.codigo_socio,
                p.id as persona_id,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos,
                e.codigo as estado_codigo,
                e.nombre as estado_nombre,
                sm.id as membresia_actual_id,
                sm.membresia_id as membresia_actual_plan_id,
                sm.fecha_inicio as membresia_actual_inicio,
                sm.fecha_fin as membresia_actual_fin,
                m.nombre as membresia_actual_nombre
            ")
            ->orderBy('p.nombres')
            ->orderBy('p.apellidos')
            ->get()
            ->map(fn ($item) => [
                'socio_id' => (int) $item->id,
                'persona_id' => (int) $item->persona_id,
                'codigo_socio' => $item->codigo_socio,
                'cedula' => $item->cedula,
                'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
                'estado_codigo' => $item->estado_codigo ?? 'ACTIVO',
                'estado_nombre' => $item->estado_nombre ?? 'Activo',
                'membresia_actual' => $item->membresia_actual_id ? [
                    'id' => (int) $item->membresia_actual_id,
                    'membresia_id' => (int) $item->membresia_actual_plan_id,
                    'nombre' => $item->membresia_actual_nombre,
                    'fecha_inicio' => $item->membresia_actual_inicio,
                    'fecha_fin' => $item->membresia_actual_fin,
                ] : null,
            ])
            ->all();
    }
}
