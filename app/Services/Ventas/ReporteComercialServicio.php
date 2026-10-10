<?php

namespace App\Services\Ventas;

use App\Services\Seguridad\AlcanceOperativoService;
use Carbon\Carbon;
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

    public function analitica(array $filtros, ?int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $sedesFiltro = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($sedesPermitidas)
            ->values()
            ->all();
        $sedes = ! empty($sedesFiltro) ? $sedesFiltro : $sedesPermitidas;

        $desde = Carbon::parse($filtros['desde'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $hasta = Carbon::parse($filtros['hasta'] ?? now()->toDateString())->endOfDay();
        $dias = $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
        $hastaAnterior = $desde->copy()->subDay()->endOfDay();
        $desdeAnterior = $hastaAnterior->copy()->subDays($dias - 1)->startOfDay();

        $baseVentas = function (Carbon $inicio, Carbon $fin) use ($sedes) {
            return DB::table('ventas.ventas as v')
                ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
                ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
                ->whereBetween('v.fecha_venta', [$inicio, $fin])
                ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
                ->where('v.estado', '<>', 'ANULADA');
        };

        $basePagos = function (Carbon $inicio, Carbon $fin) use ($sedes) {
            return DB::table('ventas.pagos as p')
                ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
                ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
                ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
                ->where('p.estado', 'CONFIRMADO')
                ->whereBetween('p.fecha_pago', [$inicio, $fin])
                ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);
        };

        $ventasActual = (float) $baseVentas($desde, $hasta)->sum('v.total');
        $ventasAnterior = (float) $baseVentas($desdeAnterior, $hastaAnterior)->sum('v.total');
        $cobradoActual = (float) $basePagos($desde, $hasta)->sum('p.monto');
        $cobradoAnterior = (float) $basePagos($desdeAnterior, $hastaAnterior)->sum('p.monto');

        $metodosPago = $basePagos($desde, $hasta)
            ->selectRaw('p.metodo_pago, COUNT(DISTINCT COALESCE(p.operacion_cobro_id, p.id::text)) as operaciones, SUM(p.monto) as total')
            ->groupBy('p.metodo_pago')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($fila) => [
                'metodo' => $fila->metodo_pago,
                'operaciones' => (int) $fila->operaciones,
                'total' => round((float) $fila->total, 2),
            ])
            ->values();

        $responsables = DB::table('ventas.ventas as v')
            ->leftJoin('seguridad.users as r', 'r.id', '=', 'v.responsable_comercial_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("COALESCE(r.name, 'Sin responsable') as responsable")
            ->selectRaw('COUNT(v.id) as transacciones')
            ->selectRaw('SUM(v.total) as total_ventas')
            ->selectRaw("SUM((SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')) as total_cobrado")
            ->groupByRaw("COALESCE(r.name, 'Sin responsable')")
            ->orderByDesc('total_ventas')
            ->limit(10)
            ->get()
            ->map(fn ($fila) => [
                'responsable' => $fila->responsable,
                'transacciones' => (int) $fila->transacciones,
                'total_ventas' => round((float) $fila->total_ventas, 2),
                'total_cobrado' => round((float) $fila->total_cobrado, 2),
            ])
            ->values();

        $membresias = DB::table('gimnasio.membresia_periodos as mp')
            ->join('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("SUM(CASE WHEN mp.numero_periodo = 1 THEN 1 ELSE 0 END) as nuevas")
            ->selectRaw("SUM(CASE WHEN mp.numero_periodo > 1 THEN 1 ELSE 0 END) as renovaciones")
            ->selectRaw('COUNT(*) as total')
            ->first();

        $saldoSql = "GREATEST(v.total - (SELECT COALESCE(SUM(pg.monto),0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO'), 0)";
        $carteraVencida = DB::table('cuentas_cobrar.cuentas as cc')
            ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->whereDate('cc.fecha_vencimiento', '<', now()->toDateString())
            ->where('v.estado', '<>', 'ANULADA')
            ->whereRaw("{$saldoSql} > 0")
            ->selectRaw("COUNT(*) as cuentas, COALESCE(SUM({$saldoSql}),0) as saldo")
            ->first();

        return [
            'periodo_actual' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'ventas' => round($ventasActual, 2),
                'cobrado' => round($cobradoActual, 2),
            ],
            'periodo_anterior' => [
                'desde' => $desdeAnterior->toDateString(),
                'hasta' => $hastaAnterior->toDateString(),
                'ventas' => round($ventasAnterior, 2),
                'cobrado' => round($cobradoAnterior, 2),
            ],
            'variacion' => [
                'ventas_porcentaje' => $this->variacionPorcentual($ventasActual, $ventasAnterior),
                'cobrado_porcentaje' => $this->variacionPorcentual($cobradoActual, $cobradoAnterior),
            ],
            'metodos_pago' => $metodosPago,
            'responsables' => $responsables,
            'membresias' => [
                'nuevas' => (int) ($membresias->nuevas ?? 0),
                'renovaciones' => (int) ($membresias->renovaciones ?? 0),
                'total' => (int) ($membresias->total ?? 0),
            ],
            'cartera_vencida' => [
                'cuentas' => (int) ($carteraVencida->cuentas ?? 0),
                'saldo' => round((float) ($carteraVencida->saldo ?? 0), 2),
            ],
        ];
    }

    private function variacionPorcentual(float $actual, float $anterior): ?float
    {
        if (abs($anterior) < 0.00001) {
            return $actual > 0 ? 100.0 : 0.0;
        }

        return round((($actual - $anterior) / $anterior) * 100, 2);
    }

}
