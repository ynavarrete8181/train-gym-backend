<?php

namespace App\Queries\Inventarios;

use Illuminate\Support\Facades\DB;

class ProductoPrecioQuery
{
    private const TYPE_LABELS = [
        'COSTO' => 'Costo',
        'VENTA' => 'Venta público',
        'SOCIO' => 'Precio socio',
        'PROMOCION' => 'Promoción',
    ];

    public function byProducto(int $productoId): array
    {
        return DB::table('inventario.producto_precios as pp')
            ->leftJoin('core.sedes as s', 's.id', '=', 'pp.sede_id')
            ->selectRaw("
                pp.id,
                pp.producto_id,
                pp.sede_id,
                s.nombre as sede_nombre,
                pp.tipo_precio,
                pp.moneda,
                pp.monto,
                pp.vigencia_inicio,
                pp.vigencia_fin,
                pp.estado,
                pp.created_by,
                pp.updated_by,
                pp.created_at,
                pp.updated_at
            ")
            ->where('pp.producto_id', $productoId)
            ->orderByDesc('pp.estado')
            ->orderByDesc('pp.vigencia_inicio')
            ->orderByDesc('pp.id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'producto_id' => (int) $row->producto_id,
                'sede_id' => $row->sede_id ? (int) $row->sede_id : null,
                'sede_nombre' => $row->sede_nombre,
                'tipo_precio' => $row->tipo_precio,
                'tipo_precio_nombre' => self::TYPE_LABELS[$row->tipo_precio] ?? $row->tipo_precio,
                'moneda' => $row->moneda,
                'monto' => $row->monto,
                'vigencia_inicio' => $row->vigencia_inicio,
                'vigencia_fin' => $row->vigencia_fin,
                'estado' => (int) $row->estado,
                'estado_nombre' => (int) $row->estado === 1 ? 'Activo' : 'Inactivo',
                'vigente' => (int) $row->estado === 1 && (!$row->vigencia_fin || $row->vigencia_fin >= now()),
                'created_by' => $row->created_by ? (int) $row->created_by : null,
                'updated_by' => $row->updated_by ? (int) $row->updated_by : null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    public function find(int $id): ?array
    {
        $row = DB::table('inventario.producto_precios as pp')
            ->leftJoin('core.sedes as s', 's.id', '=', 'pp.sede_id')
            ->selectRaw("
                pp.id,
                pp.producto_id,
                pp.sede_id,
                s.nombre as sede_nombre,
                pp.tipo_precio,
                pp.moneda,
                pp.monto,
                pp.vigencia_inicio,
                pp.vigencia_fin,
                pp.estado,
                pp.created_by,
                pp.updated_by,
                pp.created_at,
                pp.updated_at
            ")
            ->where('pp.id', $id)
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'producto_id' => (int) $row->producto_id,
            'sede_id' => $row->sede_id ? (int) $row->sede_id : null,
            'sede_nombre' => $row->sede_nombre,
            'tipo_precio' => $row->tipo_precio,
            'tipo_precio_nombre' => self::TYPE_LABELS[$row->tipo_precio] ?? $row->tipo_precio,
            'moneda' => $row->moneda,
            'monto' => $row->monto,
            'vigencia_inicio' => $row->vigencia_inicio,
            'vigencia_fin' => $row->vigencia_fin,
            'estado' => (int) $row->estado,
            'estado_nombre' => (int) $row->estado === 1 ? 'Activo' : 'Inactivo',
            'vigente' => (int) $row->estado === 1 && (!$row->vigencia_fin || $row->vigencia_fin >= now()),
            'created_by' => $row->created_by ? (int) $row->created_by : null,
            'updated_by' => $row->updated_by ? (int) $row->updated_by : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
