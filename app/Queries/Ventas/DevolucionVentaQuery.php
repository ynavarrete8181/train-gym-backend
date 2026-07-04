<?php

namespace App\Queries\Ventas;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DevolucionVentaQuery
{
    public function searchVentas(?string $term = null, ?int $sedeId = null, int $limit = 12): Collection
    {
        $query = DB::table('ventas.ventas as v')
            ->leftJoin('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->leftJoin('core.sedes as s', 's.id', '=', 'v.sede_id')
            ->selectRaw("
                v.id,
                v.referencia,
                v.sede_id,
                s.nombre as sede_nombre,
                v.persona_id,
                p.numero_identificacion as cliente_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre,
                v.fecha,
                v.fecha_consumo,
                v.tipo_venta,
                v.estado_pago,
                v.estado_devolucion,
                v.total,
                COALESCE(v.monto_devuelto, 0) as monto_devuelto,
                GREATEST(COALESCE(v.total, 0) - COALESCE(v.monto_devuelto, 0), 0) as monto_disponible
            ")
            ->where('v.estado', 1)
            ->whereRaw("COALESCE(v.estado_devolucion, 'SIN_DEVOLUCION') <> 'ANULADA'")
            ->orderByDesc('v.created_at')
            ->limit($limit);

        if ($sedeId) {
            $query->where('v.sede_id', $sedeId);
        }

        $term = trim((string) $term);
        if ($term !== '') {
            $like = '%' . mb_strtolower($term) . '%';
            $query->where(function ($builder) use ($like, $term) {
                $builder->whereRaw('LOWER(COALESCE(v.referencia, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(p.numero_identificacion, \'\')) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))) LIKE ?", [$like]);

                if (is_numeric($term)) {
                    $builder->orWhere('v.id', (int) $term);
                }
            });
        }

        return $query->get()->map(fn ($venta) => $this->attachDetalles($venta));
    }

    public function findVenta(int $ventaId): ?object
    {
        $venta = DB::table('ventas.ventas as v')
            ->leftJoin('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->leftJoin('core.sedes as s', 's.id', '=', 'v.sede_id')
            ->selectRaw("
                v.*,
                s.nombre as sede_nombre,
                p.numero_identificacion as cliente_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre
            ")
            ->where('v.id', $ventaId)
            ->first();

        return $venta ? $this->attachDetalles($venta) : null;
    }

    public function history(?int $sedeId = null, ?string $term = null, int $limit = 30): Collection
    {
        $query = DB::table('ventas.devoluciones as d')
            ->join('ventas.ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->leftJoin('core.sedes as s', 's.id', '=', 'v.sede_id')
            ->selectRaw("
                d.*,
                v.referencia as venta_referencia,
                v.sede_id,
                s.nombre as sede_nombre,
                p.numero_identificacion as cliente_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre
            ")
            ->orderByDesc('d.created_at')
            ->limit($limit);

        if ($sedeId) {
            $query->where('v.sede_id', $sedeId);
        }

        $term = trim((string) $term);
        if ($term !== '') {
            $like = '%' . mb_strtolower($term) . '%';
            $query->where(function ($builder) use ($like, $term) {
                $builder->whereRaw('LOWER(COALESCE(v.referencia, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(d.motivo, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(p.numero_identificacion, \'\')) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))) LIKE ?", [$like]);

                if (is_numeric($term)) {
                    $builder->orWhere('v.id', (int) $term)
                        ->orWhere('d.id', (int) $term);
                }
            });
        }

        return $query->get()->map(function ($row) {
            $row->metadata = is_string($row->metadata ?? null) ? (json_decode($row->metadata, true) ?: []) : ($row->metadata ?? []);
            return $row;
        });
    }

    private function attachDetalles(object $venta): object
    {
        $detalles = DB::table('ventas.venta_detalles as vd')
            ->leftJoin('inventario.productos as prod', 'prod.id', '=', 'vd.producto_id')
            ->leftJoin('socios.membresias as mem', 'mem.id', '=', 'vd.membresia_id')
            ->leftJoin(DB::raw('(
                SELECT venta_detalle_id, SUM(cantidad) as cantidad_devuelta, SUM(subtotal) as monto_devuelto
                FROM ventas.devolucion_detalles
                GROUP BY venta_detalle_id
            ) dev'), 'dev.venta_detalle_id', '=', 'vd.id')
            ->where('vd.venta_id', $venta->id)
            ->selectRaw("
                vd.id,
                vd.producto_id,
                vd.membresia_id,
                COALESCE(vd.tipo_detalle, CASE WHEN vd.membresia_id IS NOT NULL THEN 'MEMBRESIA' ELSE 'PRODUCTO' END) as tipo_detalle,
                COALESCE(prod.nombre, mem.nombre, vd.descripcion, 'Detalle') as nombre,
                vd.descripcion,
                vd.cantidad,
                vd.precio_unitario,
                vd.subtotal,
                COALESCE(dev.cantidad_devuelta, 0) as cantidad_devuelta,
                COALESCE(dev.monto_devuelto, 0) as monto_devuelto,
                GREATEST(vd.cantidad - COALESCE(dev.cantidad_devuelta, 0), 0) as cantidad_disponible,
                GREATEST(vd.subtotal - COALESCE(dev.monto_devuelto, 0), 0) as monto_disponible,
                COALESCE(prod.controla_stock, false) as controla_stock,
                COALESCE(prod.maneja_lotes, false) as maneja_lotes
            ")
            ->orderBy('vd.id')
            ->get();

        $venta->detalles = $detalles;
        return $venta;
    }
}
