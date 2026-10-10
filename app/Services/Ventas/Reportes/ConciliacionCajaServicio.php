<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class ConciliacionCajaServicio
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

        $query = DB::table('ventas.turnos_caja as t')
            ->join('ventas.cajas as c', 'c.id', '=', 't.caja_id')
            ->join('institucional.sedes as s', 's.id_sede', '=', 't.sede_id')
            ->join('seguridad.users as cajero', 'cajero.id', '=', 't.usuario_id')
            ->leftJoin('seguridad.users as conciliador', 'conciliador.id', '=', 't.conciliado_por')
            ->where('t.estado', 'CERRADA')
            ->whereBetween(DB::raw('DATE(t.fecha_cierre)'), [$desde, $hasta])
            ->whereIn('t.sede_id', $sedes)
            ->select([
                't.id',
                't.fecha_apertura',
                't.fecha_cierre',
                't.saldo_inicial',
                't.efectivo_cobrado',
                't.efectivo_esperado',
                't.efectivo_contado',
                't.diferencia',
                't.transferencia_cobrada',
                't.tarjeta_cobrada',
                't.deposito_cobrado',
                't.otros_cobrado',
                't.total_cobrado',
                't.cantidad_cobros',
                't.tipo_cierre',
                't.requiere_arqueo',
                't.conciliado_at',
                't.observaciones_cierre',
                'c.codigo as caja_codigo',
                'c.nombre as caja_nombre',
                's.nombre as sede',
                'cajero.name as cajero',
                'conciliador.name as conciliado_por_nombre',
            ]);

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(c.nombre) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(c.codigo) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(s.nombre) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(cajero.name) LIKE ?', [$texto]);
            });
        }

        if (! empty($filtros['fecha'])) {
            $valor = '%' . trim((string) $filtros['fecha']) . '%';
            $query->where(function ($q) use ($valor): void {
                $q->whereRaw('CAST(DATE(t.fecha_cierre) AS TEXT) LIKE ?', [$valor])
                    ->orWhereRaw("TO_CHAR(DATE(t.fecha_cierre), 'DD/MM/YYYY') LIKE ?", [$valor]);
            });
        }

        if (! empty($filtros['apertura'])) {
            $query->whereRaw("TO_CHAR(t.fecha_apertura, 'HH24:MI') LIKE ?", ['%' . trim((string) $filtros['apertura']) . '%']);
        }

        if (! empty($filtros['cierre'])) {
            $query->whereRaw("TO_CHAR(t.fecha_cierre, 'HH24:MI') LIKE ?", ['%' . trim((string) $filtros['cierre']) . '%']);
        }

        if (! empty($filtros['caja'])) {
            $query->whereRaw('LOWER(c.nombre) LIKE ?', ['%' . mb_strtolower((string) $filtros['caja']) . '%']);
        }

        if (! empty($filtros['cajero'])) {
            $query->whereRaw('LOWER(cajero.name) LIKE ?', ['%' . mb_strtolower((string) $filtros['cajero']) . '%']);
        }

        foreach ([
            'saldo_inicial' => 't.saldo_inicial',
            'efectivo_cobrado' => 't.efectivo_cobrado',
            'efectivo_esperado' => 't.efectivo_esperado',
            'efectivo_contado' => 't.efectivo_contado',
            'diferencia' => 't.diferencia',
            'transferencia' => 't.transferencia_cobrada',
            'tarjeta' => 't.tarjeta_cobrada',
            'deposito' => 't.deposito_cobrado',
            'otros' => 't.otros_cobrado',
        ] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $query->whereRaw("CAST(COALESCE({$columna}, 0) AS TEXT) LIKE ?", ['%' . trim((string) $filtros[$filtro]) . '%']);
            }
        }

        if (! empty($filtros['tipo_cierre'])) {
            $valores = is_array($filtros['tipo_cierre']) ? $filtros['tipo_cierre'] : [$filtros['tipo_cierre']];
            $query->whereIn('t.tipo_cierre', array_filter($valores));
        }

        if (! empty($filtros['estado_conciliacion'])) {
            $valores = is_array($filtros['estado_conciliacion']) ? $filtros['estado_conciliacion'] : [$filtros['estado_conciliacion']];
            $query->where(function ($q) use ($valores): void {
                if (in_array('CONCILIADO', $valores, true)) {
                    $q->orWhereNotNull('t.conciliado_at');
                }
                if (in_array('PENDIENTE', $valores, true)) {
                    $q->orWhere('t.requiere_arqueo', true);
                }
            });
        }

        $resumen = DB::query()
            ->fromSub(clone $query, 'reporte')
            ->selectRaw('COUNT(*) as turnos_cerrados')
            ->selectRaw('COALESCE(SUM(efectivo_esperado), 0) as efectivo_esperado')
            ->selectRaw('COALESCE(SUM(efectivo_contado), 0) as efectivo_contado')
            ->selectRaw('COALESCE(SUM(diferencia), 0) as diferencia_total')
            ->selectRaw('COALESCE(SUM(transferencia_cobrada + tarjeta_cobrada + deposito_cobrado + otros_cobrado), 0) as no_efectivo')
            ->selectRaw('SUM(CASE WHEN requiere_arqueo = true THEN 1 ELSE 0 END) as pendientes_conciliacion')
            ->first();

        $paginador = $query
            ->orderByDesc('t.fecha_cierre')
            ->orderByDesc('t.id')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'resumen' => [
                    'turnos_cerrados' => (int) ($resumen->turnos_cerrados ?? 0),
                    'efectivo_esperado' => round((float) ($resumen->efectivo_esperado ?? 0), 2),
                    'efectivo_contado' => round((float) ($resumen->efectivo_contado ?? 0), 2),
                    'diferencia_total' => round((float) ($resumen->diferencia_total ?? 0), 2),
                    'no_efectivo' => round((float) ($resumen->no_efectivo ?? 0), 2),
                    'pendientes_conciliacion' => (int) ($resumen->pendientes_conciliacion ?? 0),
                ],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'tipos_cierre' => DB::table('ventas.turnos_caja')
                        ->whereNotNull('tipo_cierre')
                        ->distinct()
                        ->orderBy('tipo_cierre')
                        ->pluck('tipo_cierre')
                        ->values(),
                    'estados_conciliacion' => [
                        ['value' => 'CONCILIADO', 'label' => 'Conciliado'],
                        ['value' => 'PENDIENTE', 'label' => 'Pendiente'],
                    ],
                ],
            ],
        ];
    }
}
