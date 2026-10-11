<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class VentasResponsableServicio
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

        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(v.total - {$pagadoSql}, 0)";

        $query = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('seguridad.users as responsable', 'responsable.id', '=', 'v.responsable_comercial_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw('v.responsable_comercial_id')
            ->selectRaw("COALESCE(responsable.name, 'Sin responsable') as responsable")
            ->selectRaw('COALESCE(c.sede_id, m.sede_id) as sede_id')
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw('COUNT(v.id) as ventas')
            ->selectRaw('COUNT(DISTINCT v.cliente_id) as clientes')
            ->selectRaw('COALESCE(SUM(v.total), 0) as total_ventas')
            ->selectRaw("COALESCE(SUM({$pagadoSql}), 0) as total_cobrado")
            ->selectRaw("COALESCE(SUM({$saldoSql}), 0) as saldo_pendiente")
            ->selectRaw('COALESCE(AVG(v.total), 0) as ticket_promedio')
            ->selectRaw("CASE WHEN SUM(v.total) > 0 THEN (SUM({$pagadoSql}) / SUM(v.total)) * 100 ELSE 0 END as porcentaje_cobrado")
            ->groupBy('v.responsable_comercial_id')
            ->groupByRaw("COALESCE(responsable.name, 'Sin responsable')")
            ->groupByRaw('COALESCE(c.sede_id, m.sede_id)')
            ->groupByRaw("COALESCE(s.nombre, 'Sin sede')");

        if (! empty($filtros['responsable_id'])) {
            $ids = collect(is_array($filtros['responsable_id']) ? $filtros['responsable_id'] : [$filtros['responsable_id']])
                ->map(fn ($id) => $id === 'SIN_RESPONSABLE' ? null : (int) $id)
                ->values();

            $incluyeSinResponsable = $ids->contains(null);
            $idsReales = $ids->filter(fn ($id) => $id !== null)->values()->all();

            $query->where(function ($q) use ($incluyeSinResponsable, $idsReales): void {
                if (! empty($idsReales)) {
                    $q->whereIn('v.responsable_comercial_id', $idsReales);
                }

                if ($incluyeSinResponsable) {
                    empty($idsReales)
                        ? $q->whereNull('v.responsable_comercial_id')
                        : $q->orWhereNull('v.responsable_comercial_id');
                }
            });
        }

        if (! empty($filtros['responsable'])) {
            $query->whereRaw("LOWER(COALESCE(responsable.name, 'Sin responsable')) LIKE ?", ['%' . mb_strtolower((string) $filtros['responsable']) . '%']);
        }

        if (! empty($filtros['ventas'])) {
            $query->havingRaw('CAST(COUNT(v.id) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['ventas']) . '%']);
        }

        if (! empty($filtros['clientes'])) {
            $query->havingRaw('CAST(COUNT(DISTINCT v.cliente_id) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['clientes']) . '%']);
        }

        if (! empty($filtros['total_ventas'])) {
            $query->havingRaw('CAST(SUM(v.total) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['total_ventas']) . '%']);
        }

        if (! empty($filtros['total_cobrado'])) {
            $query->havingRaw("CAST(SUM({$pagadoSql}) AS TEXT) LIKE ?", ['%' . trim((string) $filtros['total_cobrado']) . '%']);
        }

        if (! empty($filtros['saldo'])) {
            $query->havingRaw("CAST(SUM({$saldoSql}) AS TEXT) LIKE ?", ['%' . trim((string) $filtros['saldo']) . '%']);
        }

        if (! empty($filtros['ticket_promedio'])) {
            $query->havingRaw('CAST(AVG(v.total) AS TEXT) LIKE ?', ['%' . trim((string) $filtros['ticket_promedio']) . '%']);
        }

        if (! empty($filtros['porcentaje_cobrado'])) {
            $query->havingRaw("CAST(CASE WHEN SUM(v.total) > 0 THEN (SUM({$pagadoSql}) / SUM(v.total)) * 100 ELSE 0 END AS TEXT) LIKE ?", ['%' . trim((string) $filtros['porcentaje_cobrado']) . '%']);
        }

        $resumen = DB::query()
            ->fromSub(clone $query, 'reporte')
            ->selectRaw('COUNT(DISTINCT responsable) as responsables')
            ->selectRaw('COALESCE(SUM(ventas), 0) as ventas')
            ->selectRaw('COALESCE(SUM(total_ventas), 0) as total_ventas')
            ->selectRaw('COALESCE(SUM(total_cobrado), 0) as total_cobrado')
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) as saldo_pendiente')
            ->first();

        $paginador = $query
            ->orderByDesc('total_ventas')
            ->orderBy('responsable')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        $responsablesCatalogo = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('seguridad.users as responsable', 'responsable.id', '=', 'v.responsable_comercial_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedesPermitidas)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("COALESCE(CAST(v.responsable_comercial_id AS TEXT), 'SIN_RESPONSABLE') as id")
            ->selectRaw("COALESCE(responsable.name, 'Sin responsable') as nombre")
            ->distinct()
            ->orderBy('nombre')
            ->get();

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'resumen' => [
                    'responsables' => (int) ($resumen->responsables ?? 0),
                    'ventas' => (int) ($resumen->ventas ?? 0),
                    'total_ventas' => round((float) ($resumen->total_ventas ?? 0), 2),
                    'total_cobrado' => round((float) ($resumen->total_cobrado ?? 0), 2),
                    'saldo_pendiente' => round((float) ($resumen->saldo_pendiente ?? 0), 2),
                ],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'responsables' => $responsablesCatalogo,
                ],
            ],
        ];
    }
}
