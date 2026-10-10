<?php

namespace App\Services\Ventas;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class ReporteComercialServicio
{
    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, ?int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $sedesFiltro = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($sedesPermitidas)
            ->values()
            ->all();
        $sedes = ! empty($sedesFiltro) ? $sedesFiltro : $sedesPermitidas;

        $desde = $filtros['desde'] ?? now()->startOfMonth()->toDateString();
        $hasta = $filtros['hasta'] ?? now()->toDateString();

        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA');

        if (! empty($filtros['tipo_venta'])) {
            $tipos = is_array($filtros['tipo_venta']) ? $filtros['tipo_venta'] : [$filtros['tipo_venta']];
            $ventas->whereIn('v.tipo_venta', array_filter($tipos));
        }

        $totalVentas = (float) (clone $ventas)->sum('v.total');
        $transacciones = (clone $ventas)->count('v.id');

        $pagos = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('p.estado', 'CONFIRMADO')
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);

        $totalCobrado = (float) (clone $pagos)->sum('p.monto');
        $efectivo = (float) (clone $pagos)->where('p.metodo_pago', 'EFECTIVO')->sum('p.monto');

        $cartera = DB::table('cuentas_cobrar.cuentas as cc')
            ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->whereNotIn('v.estado', ['ANULADA', 'PAGADA'])
            ->selectRaw("COALESCE(SUM(GREATEST(v.total - (SELECT COALESCE(SUM(pg.monto),0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO'), 0)), 0) as saldo")
            ->value('saldo');

        $detalle = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("DATE(v.fecha_venta) as fecha, COALESCE(s.nombre, 'Sin sede') as sede, v.tipo_venta,
                COUNT(DISTINCT v.id) as transacciones,
                SUM(v.total) as total_ventas,
                COALESCE(SUM((SELECT COALESCE(SUM(pg.monto),0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')),0) as total_cobrado,
                COALESCE(SUM(GREATEST(v.total - (SELECT COALESCE(SUM(pg.monto),0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO'),0)),0) as saldo_pendiente")
            ->groupByRaw("DATE(v.fecha_venta), COALESCE(s.nombre, 'Sin sede'), v.tipo_venta");

        if (! empty($filtros['tipo_venta'])) {
            $tipos = is_array($filtros['tipo_venta']) ? $filtros['tipo_venta'] : [$filtros['tipo_venta']];
            $detalle->whereIn('v.tipo_venta', array_filter($tipos));
        }

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $detalle->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(COALESCE(s.nombre, \'\')) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(v.tipo_venta) LIKE ?', [$texto]);
            });
        }

        $paginador = $detalle
            ->orderByDesc('fecha')
            ->orderBy('sede')
            ->orderBy('tipo_venta')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'total_ventas' => round($totalVentas, 2),
                    'transacciones' => $transacciones,
                    'total_cobrado' => round($totalCobrado, 2),
                    'efectivo_cobrado' => round($efectivo, 2),
                    'saldo_cartera' => round((float) $cartera, 2),
                ],
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                    'tipos_venta' => DB::table('ventas.ventas')->whereIn('tipo_venta', DB::table('ventas.ventas')->distinct()->pluck('tipo_venta'))->distinct()->orderBy('tipo_venta')->pluck('tipo_venta')->values(),
                ],
            ],
        ];
    }
}
