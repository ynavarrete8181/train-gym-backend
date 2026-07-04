<?php

namespace App\Queries\Ventas;

use Illuminate\Support\Facades\DB;

class PuntoVentaQuery
{
    public function posCatalog(int $sedeId): array
    {
        return DB::table('inventario.productos as p')
            ->join('inventario.categorias_producto as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoin('inventario.producto_stock_sede as ps', function ($join) use ($sedeId) {
                $join->on('ps.producto_id', '=', 'p.id')
                    ->where('ps.sede_id', '=', $sedeId)
                    ->where('ps.estado', '=', 1);
            })

            ->selectRaw("
                p.id,
                p.codigo,
                p.nombre,
                p.descripcion,
                p.imagen_url,
                c.nombre as categoria,
                COALESCE(ps.stock_disponible, 0) as stock_disponible,
                COALESCE((
                    SELECT monto FROM inventario.producto_precios 
                    WHERE producto_id = p.id AND tipo_precio = 'VENTA' AND estado = 1 
                    AND (sede_id = ? OR sede_id IS NULL)
                    ORDER BY sede_id DESC NULLS LAST
                    LIMIT 1
                ), 0) as precio_venta
            ", [$sedeId])
            ->where('p.estado', 1)
            ->orderBy('c.nombre')
            ->orderBy('p.nombre')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'codigo' => $row->codigo,
                'nombre' => $row->nombre,
                'descripcion' => $row->descripcion,
                'imagen_url' => $row->imagen_url,
                'categoria' => $row->categoria,
                'stock_disponible' => (float) $row->stock_disponible,
                'precio_venta' => (float) $row->precio_venta,
            ])
            ->all();
    }

    public function sedeExists(int $sedeId): bool
    {
        return DB::table('core.sedes')
            ->where('id', $sedeId)
            ->where('activa', true)
            ->exists();
    }

    public function findMembership(int $membresiaId, ?int $sedeId = null): ?array
    {
        $hasPreciosSede = $this->hasPreciosSedeTable();

        $query = DB::table('socios.membresias as m');

        if ($hasPreciosSede) {
            $query->selectRaw("
                m.id,
                m.nombre,
                m.descripcion,
                m.duracion_dias,
                m.precio as precio_base,
                COALESCE((
                    SELECT mps.precio
                    FROM socios.membresia_precios_sede mps
                    WHERE mps.membresia_id = m.id
                    AND mps.sede_id = ?
                    AND mps.activa = TRUE
                    AND (mps.vigencia_inicio IS NULL OR mps.vigencia_inicio <= CURRENT_DATE)
                    AND (mps.vigencia_fin IS NULL OR mps.vigencia_fin >= CURRENT_DATE)
                    ORDER BY mps.vigencia_inicio DESC NULLS LAST, mps.id DESC
                    LIMIT 1
                ), m.precio) as precio
            ", [$sedeId])
                ->where('m.id', $membresiaId)
                ->where('m.activa', true);
        } else {
            $query->selectRaw("
                m.id,
                m.nombre,
                m.descripcion,
                m.duracion_dias,
                m.precio as precio_base,
                m.precio as precio
            ")
            ->where('m.id', $membresiaId)
            ->where('m.activa', true);
        }

        $row = $query->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'nombre' => $row->nombre,
            'descripcion' => $row->descripcion,
            'duracion_dias' => (int) $row->duracion_dias,
            'precio' => (float) $row->precio,
            'precio_base' => (float) $row->precio_base,
        ];
    }

    private function hasPreciosSedeTable(): bool
    {
        $row = DB::selectOne("SELECT to_regclass('socios.membresia_precios_sede') as table_name");

        return !empty($row?->table_name);
    }

    public function findPersona(int $personaId): ?array
    {
        $row = DB::table('core.personas')
            ->where('id', $personaId)
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'numero_identificacion' => $row->numero_identificacion,
            'nombres' => trim($row->nombres . ' ' . ($row->apellidos ?? '')),
        ];
    }

    public function findSocioByPersona(int $personaId): ?array
    {
        $row = DB::table('socios.socios')
            ->where('persona_id', $personaId)
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'persona_id' => (int) $row->persona_id,
            'codigo_socio' => $row->codigo_socio,
            'sede_id' => $row->sede_id ? (int) $row->sede_id : null,
        ];
    }

    public function latestMembershipAssignment(int $socioId): ?array
    {
        $row = DB::table('socios.socio_membresias')
            ->where('socio_id', $socioId)
            ->orderByDesc('fecha_fin')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'fecha_inicio' => $row->fecha_inicio,
            'fecha_fin' => $row->fecha_fin,
            'membresia_id' => (int) $row->membresia_id,
        ];
    }

    public function availableLotsForSale(int $productoId, int $sedeId, bool $excludeExpired = false): array
    {
        $query = DB::table('inventario.producto_lotes')
            ->select('id', 'codigo_lote', 'fecha_elaboracion', 'fecha_vencimiento', 'stock_actual')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('estado', 1)
            ->where('stock_actual', '>', 0);

        if ($excludeExpired) {
            $query->where(function ($builder) {
                $builder->whereNull('fecha_vencimiento')
                    ->orWhere('fecha_vencimiento', '>=', now()->toDateString());
            });
        }

        return $query
            ->orderByRaw("
                CASE
                    WHEN fecha_vencimiento IS NULL THEN 1
                    ELSE 0
                END ASC
            ")
            ->orderBy('fecha_vencimiento')
            ->orderBy('fecha_elaboracion')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'codigo_lote' => $row->codigo_lote,
                'fecha_elaboracion' => $row->fecha_elaboracion,
                'fecha_vencimiento' => $row->fecha_vencimiento,
                'stock_actual' => (float) $row->stock_actual,
            ])
            ->all();
    }
}
