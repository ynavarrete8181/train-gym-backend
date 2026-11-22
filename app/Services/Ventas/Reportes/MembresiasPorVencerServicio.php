<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class MembresiasPorVencerServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['codigo_contrato', 'cliente', 'paga', 'telefono', 'plan', 'fecha_fin', 'dias_restantes', 'deuda', 'monto_renovacion'];

    /** Qué pasa al vencer: se renueva sola, no se renueva porque debe, o se renueva a mano. */
    private const SITUACIONES = ['AUTOMATICA', 'DEUDA', 'MANUAL'];

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

        $venceDesde = $filtros['vence_desde'] ?? now('America/Guayaquil')->toDateString();
        $venceHasta = $filtros['vence_hasta'] ?? now('America/Guayaquil')->addDays(30)->toDateString();

        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = mp.venta_id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(COALESCE(v.total, mp.precio, 0) - {$pagadoSql}, 0)";
        $diasRestantesSql = '(mp.fecha_fin - CURRENT_DATE)';
        // Deuda del contrato: toda venta no anulada que no esté pagada bloquea la renovación automática.
        $deudaSql = "(SELECT COALESCE(SUM(GREATEST(vv.total - (SELECT COALESCE(SUM(pp.monto), 0) FROM ventas.pagos pp
            WHERE pp.venta_id = vv.id AND pp.estado = 'CONFIRMADO'), 0)), 0)
            FROM ventas.ventas vv WHERE vv.membresia_id = m.id AND vv.estado NOT IN ('ANULADA', 'PAGADA'))";
        $situacionSql = "CASE WHEN NOT COALESCE(m.renovacion_automatica, false) THEN 'MANUAL'
            WHEN {$deudaSql} > 0 THEN 'DEUDA' ELSE 'AUTOMATICA' END";
        // Precio con el que nace el próximo período (precio fijo programado o el del contrato).
        $precioRenovacionSql = "CASE WHEN m.precio_renovacion_modo = 'FIJO' AND m.precio_renovacion IS NOT NULL
            THEN m.precio_renovacion ELSE m.precio_aplicado END";
        // Descuento general que sigue al renovar: vigente al terminar el período.
        $descuentoSql = "(SELECT CASE WHEN dd.tipo = 'PORCENTAJE' THEN ROUND({$precioRenovacionSql} * dd.valor / 100, 2)
            ELSE LEAST(dd.valor, {$precioRenovacionSql}) END
            FROM membresias.descuentos_deportistas dd WHERE dd.deportista_id = m.deportista_id AND dd.activo
            AND (dd.plan_id IS NULL OR dd.plan_id = m.plan_id) AND (dd.modalidad_id IS NULL OR dd.modalidad_id = m.modalidad_id)
            AND dd.sede_id IS NULL AND dd.fecha_inicio <= mp.fecha_fin AND (dd.fecha_fin IS NULL OR dd.fecha_fin >= mp.fecha_fin)
            ORDER BY dd.modalidad_id NULLS LAST, dd.fecha_inicio DESC LIMIT 1)";

        $query = DB::table('membresias.membresia_periodos as mp')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->join('membresias.planes as plan', 'plan.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'modalidad.id', '=', 'm.modalidad_id')
            ->join('clientes.deportistas as d', 'd.id', '=', 'm.deportista_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as cliente_user', 'cliente_user.id', '=', 'd.usuario_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->leftJoin('personas.personas as tutor', 'tutor.id', '=', DB::raw('(SELECT r.representante_persona_id FROM clientes.deportista_representantes r
                WHERE r.deportista_id = d.id AND r.activo AND r.responsable_pago ORDER BY r.es_principal DESC, r.id LIMIT 1)'))
            ->whereIn('m.sede_id', $sedes)
            // El pase se vende cada día: no es una renovación ni un contrato por vencer.
            ->whereRaw("COALESCE(plan.tipo_producto, 'MEMBRESIA') <> 'PASE_DIARIO'")
            ->whereBetween('mp.fecha_fin', [$venceDesde, $venceHasta])
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('membresias.membresia_periodos as siguiente')
                    ->whereColumn('siguiente.membresia_id', 'mp.membresia_id')
                    ->whereColumn('siguiente.numero_periodo', '>', 'mp.numero_periodo');
            })
            ->whereNotIn(DB::raw("UPPER(COALESCE(m.estado, ''))"), ['ANULADA', 'CANCELADA', 'FINALIZADA'])
            ->selectRaw("mp.id, m.id as membresia_id, m.codigo_contrato, COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Cliente sin nombre') as cliente")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '')) as identificacion")
            ->selectRaw('plan.nombre as plan, plan.renovable')
            ->selectRaw("COALESCE(modalidad.nombre, 'Sin modalidad') as modalidad")
            ->addSelect(
                'mp.numero_periodo',
                'mp.fecha_inicio',
                'mp.fecha_fin',
                'mp.estado as estado_periodo',
                'm.estado as estado_membresia',
                'mp.precio',
                'mp.venta_id',
                'v.numero as venta_numero'
            )
            ->selectRaw("{$diasRestantesSql} as dias_restantes")
            ->selectRaw("{$pagadoSql} as total_cobrado")
            ->selectRaw("{$saldoSql} as saldo_pendiente")
            ->selectRaw("{$deudaSql} as deuda, {$situacionSql} as situacion")
            ->selectRaw("{$precioRenovacionSql} as precio_renovacion, COALESCE({$descuentoSql}, 0) as descuento_renovacion")
            ->selectRaw("GREATEST({$precioRenovacionSql} - COALESCE({$descuentoSql}, 0), 0) as monto_renovacion")
            ->selectRaw("mp.fecha_fin - 7 as fecha_programacion")
            ->selectRaw("COALESCE(NULLIF(TRIM(tutor.nombre_completo), '') || ' (tutor)', COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Cliente sin nombre')) as paga")
            ->selectRaw("COALESCE(NULLIF(TRIM(tutor.telefono), ''), NULLIF(TRIM(persona.telefono), ''), NULLIF(TRIM(d.telefono), '')) as telefono");

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(m.codigo_contrato) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.nombre_completo, cliente_user.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.identificacion, cliente_user.cedula, '')) LIKE ?", [$texto])
                    ->orWhereRaw('LOWER(plan.nombre) LIKE ?', [$texto]);
            });
        }

        if (! empty($filtros['renovable'])) {
            $valores = collect(is_array($filtros['renovable']) ? $filtros['renovable'] : [$filtros['renovable']])
                ->map(fn ($valor) => filter_var($valor, FILTER_VALIDATE_BOOLEAN))
                ->values()
                ->all();
            $query->whereIn('plan.renovable', $valores);
        }

        if (! empty($filtros['estado_periodo'])) {
            $valores = is_array($filtros['estado_periodo']) ? $filtros['estado_periodo'] : [$filtros['estado_periodo']];
            $query->whereIn('mp.estado', array_filter($valores));
        }

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        // Tarjetas: se cuentan antes de filtrar por situación o por "vencen en 7 días".
        $porSituacion = (clone $reporte)
            ->selectRaw("SUM(CASE WHEN situacion = 'AUTOMATICA' THEN 1 ELSE 0 END) as automatica")
            ->selectRaw("SUM(CASE WHEN situacion = 'DEUDA' THEN 1 ELSE 0 END) as deuda")
            ->selectRaw("SUM(CASE WHEN situacion = 'DEUDA' THEN deuda ELSE 0 END) as deuda_total")
            ->selectRaw("SUM(CASE WHEN situacion = 'MANUAL' THEN 1 ELSE 0 END) as manual")
            ->selectRaw('SUM(CASE WHEN dias_restantes BETWEEN 0 AND 7 THEN 1 ELSE 0 END) as siete')
            ->first();
        if (! empty($filtros['situacion']) && in_array($filtros['situacion'], self::SITUACIONES, true)) {
            $reporte->where('situacion', $filtros['situacion']);
        }
        if (! empty($filtros['proximos_7'])) {
            $reporte->whereBetween('dias_restantes', [0, 7]);
        }

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(*) as total_por_vencer')
            ->selectRaw('SUM(CASE WHEN dias_restantes BETWEEN 0 AND 7 THEN 1 ELSE 0 END) as vence_7_dias')
            ->selectRaw('SUM(CASE WHEN dias_restantes BETWEEN 0 AND 15 THEN 1 ELSE 0 END) as vence_15_dias')
            ->selectRaw('SUM(CASE WHEN renovable = true THEN 1 ELSE 0 END) as renovables')
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) as saldo_pendiente')
            ->first();

        $paginador = $reporte
            ->orderBy('fecha_fin')
            ->orderBy('cliente')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'rango_vencimiento' => [
                    'desde' => $venceDesde,
                    'hasta' => $venceHasta,
                ],
                'resumen' => [
                    'total_por_vencer' => (int) ($resumen->total_por_vencer ?? 0),
                    'vence_7_dias' => (int) ($resumen->vence_7_dias ?? 0),
                    'vence_15_dias' => (int) ($resumen->vence_15_dias ?? 0),
                    'renovables' => (int) ($resumen->renovables ?? 0),
                    'saldo_pendiente' => round((float) ($resumen->saldo_pendiente ?? 0), 2),
                    'situaciones' => [
                        'AUTOMATICA' => (int) ($porSituacion->automatica ?? 0),
                        'DEUDA' => (int) ($porSituacion->deuda ?? 0),
                        'MANUAL' => (int) ($porSituacion->manual ?? 0),
                    ],
                    'deuda_total' => round((float) ($porSituacion->deuda_total ?? 0), 2),
                    'proximos_7' => (int) ($porSituacion->siete ?? 0),
                ],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'estados_periodo' => DB::table('membresias.membresia_periodos')
                        ->whereNotNull('estado')
                        ->distinct()
                        ->orderBy('estado')
                        ->pluck('estado')
                        ->values(),
                    'renovable' => [
                        ['value' => '1', 'label' => 'Sí'],
                        ['value' => '0', 'label' => 'No'],
                    ],
                ],
            ],
        ];
    }
}
