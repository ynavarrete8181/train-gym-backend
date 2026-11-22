<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

/**
 * Cobros del período: una fila por cobro (las cuentas que un tutor paga juntas son un solo
 * cobro), con quién pagó, qué cuentas pagó, método, número de comprobante, banco, cajero y sede.
 * Sirve al administrador para revisar cualquier rango de fechas y cuadrar con el banco.
 */
class CobrosPeriodoServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['fecha', 'codigo_cobro', 'pagado_por', 'cuentas', 'metodo_pago', 'referencia', 'banco', 'cajero', 'sede', 'total'];

    private const OPERACION = "COALESCE(p.operacion_cobro_id, 'PAGO-' || p.id::text)";

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
        $operacion = self::OPERACION;
        $sedeExpr = 'COALESCE(caja_pago.sede_id, caja_venta.sede_id, m.sede_id)';
        $transferencia = "p.metodo_pago IN ('TRANSFERENCIA', 'DEPOSITO')";

        $query = DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as caja_pago', 'caja_pago.id', '=', 'p.caja_id')
            ->leftJoin('ventas.cajas as caja_venta', 'caja_venta.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw($sedeExpr))
            ->leftJoin('clientes.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('personas.personas as pagador', 'pagador.id', '=', 'p.pagador_persona_id')
            ->leftJoin('seguridad.users as cajero', 'cajero.id', '=', 'p.usuario_id')
            ->leftJoin('configuracion.instituciones_financieras as inst', 'inst.id', '=', 'p.institucion_financiera_id')
            ->where('p.estado', 'CONFIRMADO')
            ->whereBetween(DB::raw('DATE(p.fecha_pago)'), [$desde, $hasta])
            ->whereIn(DB::raw($sedeExpr), $sedes)
            ->selectRaw("{$operacion} as operacion")
            ->selectRaw('DATE(MAX(p.fecha_pago))::text as fecha')
            ->selectRaw("TO_CHAR(MAX(p.fecha_pago), 'HH24:MI') as hora")
            ->selectRaw('MAX(p.codigo_cobro) as codigo_cobro')
            // Un cobro de tutor sale a su nombre; los demás, a nombre del cliente.
            ->selectRaw("COALESCE(MAX(NULLIF(TRIM(pagador.nombre_completo), '')) || ' (tutor)', MAX(NULLIF(TRIM(persona.nombre_completo), '')), 'Consumidor final') as pagado_por")
            ->selectRaw("STRING_AGG(DISTINCT COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), 'Consumidor final') || ' · ' || COALESCE(m.codigo_contrato, v.numero), ', ') as cuentas")
            ->selectRaw('COUNT(DISTINCT p.venta_id) as numero_cuentas')
            ->selectRaw("STRING_AGG(DISTINCT p.metodo_pago, ', ') as metodo_pago")
            ->selectRaw("STRING_AGG(DISTINCT CASE WHEN {$transferencia} THEN COALESCE(NULLIF(TRIM(p.referencia), ''), 'Sin número') END, ', ') as referencia")
            ->selectRaw("STRING_AGG(DISTINCT CASE WHEN {$transferencia} THEN COALESCE(inst.nombre, NULLIF(TRIM(p.banco), ''), 'Sin banco') END, ', ') as banco")
            ->selectRaw("COALESCE(MAX(NULLIF(TRIM(cajero.name), '')), 'Sin usuario') as cajero")
            ->selectRaw("COALESCE(MAX(s.nombre), 'Sin sede') as sede")
            ->selectRaw('SUM(p.monto) as total')
            ->groupByRaw($operacion);

        if (! empty($filtros['metodo_pago'])) {
            $metodos = array_filter(is_array($filtros['metodo_pago']) ? $filtros['metodo_pago'] : [$filtros['metodo_pago']]);
            $query->whereIn(DB::raw($operacion), DB::table('ventas.pagos as p')->where('p.estado', 'CONFIRMADO')
                ->whereIn('p.metodo_pago', $metodos)->selectRaw("{$operacion}"));
        }

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        // Transferencias o depósitos sin número de comprobante o sin banco: hay que completarlos.
        $incompleto = fn ($q) => $q->where(fn ($w) => $w->where('banco', 'like', '%Sin banco%')->orWhere('referencia', 'like', '%Sin número%'));
        $porCompletar = $incompleto(clone $reporte)->selectRaw('COUNT(*) as cobros, COALESCE(SUM(total), 0) as total')->first();
        if (! empty($filtros['por_completar'])) {
            $reporte = $incompleto($reporte);
        }

        // Totales sobre los cobros que quedan después de todos los filtros.
        $totales = (clone $reporte)->selectRaw('COUNT(*) as cobros, COALESCE(SUM(total), 0) as total, COALESCE(SUM(numero_cuentas), 0) as cuentas')->first();
        $porMetodo = DB::table('ventas.pagos as p')
            ->where('p.estado', 'CONFIRMADO')
            ->whereIn(DB::raw($operacion), (clone $reporte)->select('operacion'))
            ->groupBy('p.metodo_pago')->orderBy('p.metodo_pago')
            ->selectRaw('p.metodo_pago as metodo, SUM(p.monto) as total')
            ->get()->map(fn ($f) => ['metodo' => $f->metodo, 'total' => round((float) $f->total, 2)])->values();

        $paginador = $reporte
            ->orderByDesc('fecha')->orderByDesc('hora')->orderByDesc('codigo_cobro')
            ->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'cobros' => (int) $totales->cobros,
                    'cuentas' => (int) $totales->cuentas,
                    'total_cobrado' => round((float) $totales->total, 2),
                    'por_metodo' => $porMetodo,
                    'por_completar' => ['cobros' => (int) $porCompletar->cobros, 'total' => round((float) $porCompletar->total, 2)],
                ],
                'periodo' => ['desde' => $desde, 'hasta' => $hasta],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                    'metodos_pago' => DB::table('ventas.pagos')->where('estado', 'CONFIRMADO')->distinct()->orderBy('metodo_pago')->pluck('metodo_pago')->values(),
                ],
            ],
        ];
    }
}
