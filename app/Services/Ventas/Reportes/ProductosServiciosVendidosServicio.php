<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

/**
 * Productos y servicios vendidos: una fila por lo que se vende (plan y modalidad con su nombre
 * actual, o el producto), con ventas, clientes, descuentos y total neto. El descuento de cada venta
 * se reparte entre sus líneas en proporción a su importe.
 */
class ProductosServiciosVendidosServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['item', 'tipo_item', 'ventas', 'clientes', 'cantidad', 'precio_promedio', 'descuentos', 'total_vendido'];
    private const TIPOS = "CASE WHEN v.membresia_id IS NOT NULL THEN 'MEMBRESIA' WHEN d.producto_id IS NOT NULL THEN 'PRODUCTO'
        ELSE UPPER(COALESCE(NULLIF(TRIM(d.tipo_item), ''), NULLIF(TRIM(v.tipo_venta), ''), 'OTRO')) END";

    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $sedes = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()->map(fn ($id) => (int) $id)->intersect($sedesPermitidas)->values()->all();
        $sedes = $sedes ?: $sedesPermitidas;

        $desde = $filtros['desde'] ?? now('America/Guayaquil')->startOfMonth()->toDateString();
        $hasta = $filtros['hasta'] ?? now('America/Guayaquil')->toDateString();
        $sedeSql = 'COALESCE(c.sede_id, m.sede_id)';
        $tipoSql = self::TIPOS;
        // Nombre actual de lo vendido: plan y modalidad (sin el código del contrato) o el producto.
        $itemSql = "CASE WHEN v.membresia_id IS NOT NULL THEN plan.nombre || COALESCE(' · ' || modalidad.nombre, '')
            ELSE COALESCE(NULLIF(TRIM(p.nombre), ''), NULLIF(TRIM(d.descripcion), ''), NULLIF(TRIM(v.concepto), ''), 'Sin descripción') END";
        $descuentoLineaSql = 'COALESCE(v.descuento, 0) * d.total_linea / NULLIF(v.subtotal, 0)';

        $query = DB::table('ventas.venta_detalles as d')
            ->join('ventas.ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('membresias.planes as plan', 'plan.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'modalidad.id', '=', 'm.modalidad_id')
            ->leftJoin('inventario.productos as p', 'p.id', '=', 'd.producto_id')
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw($sedeSql), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("{$itemSql} as item, {$tipoSql} as tipo_item")
            ->selectRaw('COUNT(DISTINCT v.id) as ventas, COUNT(DISTINCT v.cliente_id) as clientes')
            ->selectRaw('COALESCE(SUM(d.cantidad), 0) as cantidad')
            ->selectRaw('COALESCE(AVG(d.precio_unitario), 0) as precio_promedio')
            ->selectRaw("ROUND(COALESCE(SUM({$descuentoLineaSql}), 0), 2) as descuentos")
            ->selectRaw("ROUND(COALESCE(SUM(d.total_linea - COALESCE({$descuentoLineaSql}, 0)), 0), 2) as total_vendido")
            ->groupByRaw($itemSql)
            ->groupByRaw($tipoSql);

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->whereRaw("LOWER({$itemSql}) LIKE ?", [$texto]);
        }
        if (! empty($filtros['tipo_item'])) {
            $valores = is_array($filtros['tipo_item']) ? $filtros['tipo_item'] : [$filtros['tipo_item']];
            $query->whereIn(DB::raw($tipoSql), array_map('strtoupper', array_filter($valores)));
        }

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(*) as items_distintos, COALESCE(SUM(ventas), 0) as ventas, COALESCE(SUM(cantidad), 0) as unidades')
            ->selectRaw('COALESCE(SUM(total_vendido), 0) as total_vendido, COALESCE(SUM(descuentos), 0) as descuentos')
            ->selectRaw("COALESCE(SUM(total_vendido) FILTER (WHERE tipo_item = 'MEMBRESIA'), 0) as membresias")
            ->selectRaw("COALESCE(SUM(total_vendido) FILTER (WHERE tipo_item <> 'MEMBRESIA'), 0) as otros")
            ->first();
        $masVendido = (clone $reporte)->orderByDesc('ventas')->orderByDesc('total_vendido')->first(['item', 'ventas', 'total_vendido']);
        $total = (float) $resumen->total_vendido;

        $paginador = $reporte->orderByDesc('total_vendido')->orderBy('item')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);
        $datos = collect($paginador->items())->map(function ($fila) use ($total) {
            $fila->porcentaje = $total > 0 ? round((float) $fila->total_vendido / $total * 100, 1) : 0;
            return $fila;
        })->all();

        return [
            'datos' => $datos,
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'resumen' => [
                    'items_distintos' => (int) $resumen->items_distintos,
                    'ventas' => (int) $resumen->ventas,
                    'unidades' => round((float) $resumen->unidades, 2),
                    'total_vendido' => round($total, 2),
                    'descuentos' => round((float) $resumen->descuentos, 2),
                    'membresias' => round((float) $resumen->membresias, 2),
                    'productos_servicios' => round((float) $resumen->otros, 2),
                    'mas_vendido' => $masVendido ? ['item' => $masVendido->item, 'ventas' => (int) $masVendido->ventas,
                        'total' => round((float) $masVendido->total_vendido, 2)] : null,
                ],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                    'tipos_item' => ['MEMBRESIA', 'PRODUCTO', 'SERVICIO'],
                ],
            ],
        ];
    }
}
