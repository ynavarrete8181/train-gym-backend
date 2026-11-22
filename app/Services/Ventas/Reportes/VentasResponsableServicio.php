<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

/**
 * Ventas y cobros por usuario: por cada persona (y "Sistema" para lo automático), cuántas ventas
 * registró y cuánto cobró, separado en efectivo y transferencia. Sirve para controlar el dinero
 * que recibió cada cajero. Las ventas se cuentan por su fecha y los cobros por la fecha del pago.
 */
class VentasResponsableServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['usuario', 'rol', 'ventas', 'total_ventas', 'cobros', 'total_cobrado', 'efectivo', 'transferencia'];

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

        $ventas = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("CASE WHEN v.generado_por_tipo = 'SISTEMA' OR v.usuario_id IS NULL THEN NULL ELSE v.usuario_id END as usuario_id")
            ->selectRaw('COUNT(*) as ventas, SUM(v.total) as total_ventas')
            ->selectRaw('0 as cobros, 0 as total_cobrado, 0 as efectivo, 0 as transferencia, 0 as otros')
            ->groupByRaw("CASE WHEN v.generado_por_tipo = 'SISTEMA' OR v.usuario_id IS NULL THEN NULL ELSE v.usuario_id END");

        $cobros = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as cp', 'cp.id', '=', 'p.caja_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('p.estado', 'CONFIRMADO')
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(cp.sede_id, c.sede_id, m.sede_id)'), $sedes)
            ->selectRaw('p.usuario_id')
            ->selectRaw('0 as ventas, 0 as total_ventas')
            ->selectRaw("COUNT(DISTINCT COALESCE(p.operacion_cobro_id, p.id::text)) as cobros, SUM(p.monto) as total_cobrado")
            ->selectRaw("SUM(p.monto) FILTER (WHERE p.metodo_pago = 'EFECTIVO') as efectivo")
            ->selectRaw("SUM(p.monto) FILTER (WHERE p.metodo_pago IN ('TRANSFERENCIA', 'DEPOSITO')) as transferencia")
            ->selectRaw("SUM(p.monto) FILTER (WHERE p.metodo_pago NOT IN ('EFECTIVO', 'TRANSFERENCIA', 'DEPOSITO')) as otros")
            ->groupBy('p.usuario_id');

        $query = DB::query()->fromSub($ventas->unionAll($cobros), 'x')
            ->leftJoin('seguridad.users as u', 'u.id', '=', 'x.usuario_id')
            ->leftJoin('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->selectRaw("x.usuario_id, CASE WHEN x.usuario_id IS NULL THEN 'Sistema (automático)' ELSE COALESCE(NULLIF(TRIM(u.name), ''), 'Usuario ' || x.usuario_id) END as usuario")
            ->selectRaw("CASE WHEN x.usuario_id IS NULL THEN 'Renovaciones y cuotas' ELSE INITCAP(LOWER(COALESCE(r.role, ''))) END as rol")
            ->selectRaw('SUM(x.ventas)::int as ventas, COALESCE(SUM(x.total_ventas), 0) as total_ventas')
            ->selectRaw('SUM(x.cobros)::int as cobros, COALESCE(SUM(x.total_cobrado), 0) as total_cobrado')
            ->selectRaw('COALESCE(SUM(x.efectivo), 0) as efectivo, COALESCE(SUM(x.transferencia), 0) as transferencia, COALESCE(SUM(x.otros), 0) as otros')
            ->groupBy('x.usuario_id', 'u.name', 'r.role');

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(*) as usuarios, COALESCE(SUM(ventas), 0) as ventas, COALESCE(SUM(total_ventas), 0) as total_ventas')
            ->selectRaw('COALESCE(SUM(cobros), 0) as cobros, COALESCE(SUM(total_cobrado), 0) as total_cobrado')
            ->selectRaw('COALESCE(SUM(efectivo), 0) as efectivo, COALESCE(SUM(transferencia), 0) as transferencia, COALESCE(SUM(otros), 0) as otros')
            ->first();

        $paginador = $reporte->orderByDesc('total_cobrado')->orderByDesc('total_ventas')->orderBy('usuario')
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
                    'usuarios' => (int) $resumen->usuarios,
                    'ventas' => (int) $resumen->ventas,
                    'total_ventas' => round((float) $resumen->total_ventas, 2),
                    'cobros' => (int) $resumen->cobros,
                    'total_cobrado' => round((float) $resumen->total_cobrado, 2),
                    'efectivo' => round((float) $resumen->efectivo, 2),
                    'transferencia' => round((float) $resumen->transferencia, 2),
                    'otros' => round((float) $resumen->otros, 2),
                ],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                ],
            ],
        ];
    }
}
