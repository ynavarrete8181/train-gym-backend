<?php

namespace App\Queries\Inventarios;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProductoMovimientoQuery
{
    public function search(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 100), 300));

        return $this->baseQuery($filters)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->mapRow($row))
            ->all();
    }

    private function baseQuery(array $filters): Builder
    {
        $query = DB::table('inventario.movimientos_inventario as m')
            ->join('inventario.productos as p', 'p.id', '=', 'm.producto_id')
            ->join('core.sedes as s', 's.id', '=', 'm.sede_id')
            ->leftJoin('inventario.producto_lotes as l', 'l.id', '=', 'm.lote_id')
            ->selectRaw("
                m.id,
                m.producto_id,
                m.sede_id,
                s.nombre as sede_nombre,
                m.lote_id,
                m.tipo_movimiento,
                m.motivo,
                m.cantidad,
                m.stock_anterior,
                m.stock_nuevo,
                m.costo_unitario,
                m.precio_unitario,
                m.referencia_tipo,
                m.referencia_id,
                m.observacion,
                m.created_by,
                m.created_at,
                p.codigo as producto_codigo,
                p.nombre as producto_nombre,
                p.maneja_lotes,
                p.maneja_vencimiento,
                l.codigo_lote,
                l.fecha_vencimiento
            ")
            ->orderByDesc('m.created_at');

        if (!empty($filters['producto_id'])) {
            $query->where('m.producto_id', (int) $filters['producto_id']);
        }

        if (!empty($filters['sede_id'])) {
            $query->where('m.sede_id', (int) $filters['sede_id']);
        }

        if (!empty($filters['tipo_movimiento'])) {
            $query->where('m.tipo_movimiento', $filters['tipo_movimiento']);
        }

        if (!empty($filters['motivo'])) {
            $query->where('m.motivo', $filters['motivo']);
        }

        if (!empty($filters['fecha_desde'])) {
            $query->where('m.created_at', '>=', $filters['fecha_desde'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->where('m.created_at', '<=', $filters['fecha_hasta'] . ' 23:59:59');
        }

        return $query;
    }

    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'producto_id' => (int) $row->producto_id,
            'sede_id' => (int) $row->sede_id,
            'sede_nombre' => $row->sede_nombre,
            'lote_id' => $row->lote_id ? (int) $row->lote_id : null,
            'tipo_movimiento' => $row->tipo_movimiento,
            'motivo' => $row->motivo,
            'cantidad' => $row->cantidad,
            'stock_anterior' => $row->stock_anterior,
            'stock_nuevo' => $row->stock_nuevo,
            'costo_unitario' => $row->costo_unitario,
            'precio_unitario' => $row->precio_unitario,
            'referencia_tipo' => $row->referencia_tipo,
            'referencia_id' => $row->referencia_id ? (int) $row->referencia_id : null,
            'observacion' => $row->observacion,
            'created_by' => (int) $row->created_by,
            'created_at' => $row->created_at,
            'producto_codigo' => $row->producto_codigo,
            'producto_nombre' => $row->producto_nombre,
            'maneja_lotes' => (bool) $row->maneja_lotes,
            'maneja_vencimiento' => (bool) $row->maneja_vencimiento,
            'codigo_lote' => $row->codigo_lote,
            'fecha_vencimiento' => $row->fecha_vencimiento,
        ];
    }
}
