<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class ProductosServiciosVendidosServicio
{
    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $sedesSolicitadas = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($sedesPermitidas)
            ->values()
            ->all();
        $sedes = $sedesSolicitadas ?: $sedesPermitidas;

        $desde = $filtros['desde'] ?? now()->startOfMonth()->toDateString();
        $hasta = $filtros['hasta'] ?? now()->toDateString();

        $tipoSql = "UPPER(COALESCE(NULLIF(TRIM(d.tipo_item), ''), CASE WHEN d.producto_id IS NOT NULL THEN 'PRODUCTO' ELSE NULLIF(TRIM(v.tipo_venta), '') END, 'OTRO'))";
        $descripcionSql = "COALESCE(NULLIF(TRIM(p.nombre), ''), NULLIF(TRIM(d.descripcion), ''), NULLIF(TRIM(v.concepto), ''), 'Sin descripción')";
        $sedeSql = 'COALESCE(c.sede_id, m.sede_id)';

        $query = DB::table('ventas.venta_detalles as d')
            ->join('ventas.ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw($sedeSql))
            ->leftJoin('inventario.productos as p', 'p.id', '=', 'd.producto_id')
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw($sedeSql), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("{$tipoSql} as tipo_item")
            ->selectRaw("{$descripcionSql} as item")
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw('COUNT(DISTINCT v.id) as ventas')
            ->selectRaw('COALESCE(SUM(d.cantidad), 0) as cantidad')
            ->selectRaw('COALESCE(AVG(d.precio_unitario), 0) as precio_promedio')
            ->selectRaw('COALESCE(SUM(d.total_linea), 0) as total_vendido')
            ->groupByRaw($tipoSql)
            ->groupByRaw($descripcionSql)
            ->groupByRaw("COALESCE(s.nombre, 'Sin sede')");

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto, $descripcionSql, $tipoSql): void {
                $q->whereRaw("LOWER({$descripcionSql}) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER({$tipoSql}) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['tipo_item'])) {
            $valores = is_array($filtros['tipo_item']) ? $filtros['tipo_item'] : [$filtros['tipo_item']];
            $query->whereIn(DB::raw($tipoSql), array_map('strtoupper', array_filter($valores)));
        }

        if (! empty($filtros['item'])) {
            $query->whereRaw("LOWER({$descripcionSql}) LIKE ?", ['%' . mb_strtolower((string) $filtros['item']) . '%']);
        }

        if (! empty($filtros['ventas'])) {
            $query->havingRaw('CAST(COUNT(DISTINCT v.id) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['ventas']) . '%']);
        }

        if (! empty($filtros['cantidad'])) {
            $query->havingRaw('CAST(SUM(d.cantidad) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['cantidad']) . '%']);
        }

        if (! empty($filtros['precio_promedio'])) {
            $query->havingRaw('CAST(AVG(d.precio_unitario) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['precio_promedio']) . '%']);
        }

        if (! empty($filtros['total_vendido'])) {
            $query->havingRaw('CAST(SUM(d.total_linea) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['total_vendido']) . '%']);
        }

        $resumen = DB::query()
            ->fromSub(clone $query, 'reporte')
            ->selectRaw('COUNT(*) as items_distintos')
            ->selectRaw('COALESCE(SUM(ventas), 0) as ventas')
            ->selectRaw('COALESCE(SUM(cantidad), 0) as unidades')
            ->selectRaw('COALESCE(SUM(total_vendido), 0) as total_vendido')
            ->first();

        $paginador = $query
            ->orderByDesc('total_vendido')
            ->orderBy('item')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        $tipos = DB::table('ventas.venta_detalles as d')
            ->join('ventas.ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw($sedeSql), $sedesPermitidas)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("{$tipoSql} as tipo")
            ->distinct()
            ->orderBy('tipo')
            ->pluck('tipo')
            ->values();

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'resumen' => [
                    'items_distintos' => (int) ($resumen->items_distintos ?? 0),
                    'ventas' => (int) ($resumen->ventas ?? 0),
                    'unidades' => round((float) ($resumen->unidades ?? 0), 2),
                    'total_vendido' => round((float) ($resumen->total_vendido ?? 0), 2),
                ],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'tipos_item' => $tipos,
                ],
            ],
        ];
    }
}
