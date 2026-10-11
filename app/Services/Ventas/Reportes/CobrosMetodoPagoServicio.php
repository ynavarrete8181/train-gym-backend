<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class CobrosMetodoPagoServicio
{
    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, int $usuarioId): array
    {
        $sedesPermitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $sedes = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($sedesPermitidas)
            ->values()
            ->all();
        $sedes = $sedes ?: $sedesPermitidas;

        $desde = $filtros['desde'] ?? now()->startOfMonth()->toDateString();
        $hasta = $filtros['hasta'] ?? now()->toDateString();

        $query = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->where('p.estado', 'CONFIRMADO')
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->selectRaw("DATE(p.fecha_pago) as fecha, COALESCE(s.nombre, 'Sin sede') as sede, p.metodo_pago")
            ->selectRaw("COUNT(DISTINCT COALESCE(p.operacion_cobro_id, p.id::text)) as operaciones")
            ->selectRaw('SUM(p.monto) as total')
            ->groupByRaw("DATE(p.fecha_pago), COALESCE(s.nombre, 'Sin sede'), p.metodo_pago");

        if (! empty($filtros['fecha'])) {
            $valor = '%' . trim((string) $filtros['fecha']) . '%';
            $query->where(function ($q) use ($valor): void {
                $q->whereRaw('CAST(DATE(p.fecha_pago) AS TEXT) LIKE ?', [$valor])
                    ->orWhereRaw("TO_CHAR(DATE(p.fecha_pago), 'DD/MM/YYYY') LIKE ?", [$valor]);
            });
        }

        if (! empty($filtros['metodo_pago'])) {
            $metodos = is_array($filtros['metodo_pago']) ? $filtros['metodo_pago'] : [$filtros['metodo_pago']];
            $query->whereIn('p.metodo_pago', array_filter($metodos));
        }

        if (! empty($filtros['operaciones'])) {
            $query->havingRaw("CAST(COUNT(DISTINCT COALESCE(p.operacion_cobro_id, p.id::text)) AS TEXT) LIKE ?", ['%' . trim((string) $filtros['operaciones']) . '%']);
        }

        if (! empty($filtros['total'])) {
            $query->havingRaw('CAST(SUM(p.monto) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['total']) . '%']);
        }

        $resumen = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('p.estado', 'CONFIRMADO')
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);

        if (! empty($filtros['fecha'])) {
            $valor = '%' . trim((string) $filtros['fecha']) . '%';
            $resumen->where(function ($q) use ($valor): void {
                $q->whereRaw('CAST(DATE(p.fecha_pago) AS TEXT) LIKE ?', [$valor])
                    ->orWhereRaw("TO_CHAR(DATE(p.fecha_pago), 'DD/MM/YYYY') LIKE ?", [$valor]);
            });
        }

        if (! empty($filtros['metodo_pago'])) {
            $metodos = is_array($filtros['metodo_pago']) ? $filtros['metodo_pago'] : [$filtros['metodo_pago']];
            $resumen->whereIn('p.metodo_pago', array_filter($metodos));
        }

        $totalCobrado = (float) (clone $resumen)->sum('p.monto');
        $operaciones = (clone $resumen)->selectRaw("COUNT(DISTINCT COALESCE(p.operacion_cobro_id, p.id::text)) as total")->value('total');

        $paginador = $query
            ->orderByDesc('fecha')
            ->orderBy('sede')
            ->orderBy('p.metodo_pago')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'total_cobrado' => round($totalCobrado, 2),
                    'operaciones' => (int) $operaciones,
                ],
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                    'metodos_pago' => DB::table('ventas.pagos')->where('estado', 'CONFIRMADO')->distinct()->orderBy('metodo_pago')->pluck('metodo_pago')->values(),
                ],
            ],
        ];
    }
}
