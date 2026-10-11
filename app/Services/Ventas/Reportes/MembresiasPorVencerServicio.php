<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class MembresiasPorVencerServicio
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

        $venceDesde = $filtros['vence_desde'] ?? now()->toDateString();
        $venceHasta = $filtros['vence_hasta'] ?? now()->addDays(30)->toDateString();

        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = mp.venta_id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(COALESCE(v.total, mp.precio, 0) - {$pagadoSql}, 0)";
        $diasRestantesSql = '(mp.fecha_fin - CURRENT_DATE)';

        $query = DB::table('membresias.membresia_periodos as mp')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->join('membresias.planes as plan', 'plan.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'modalidad.id', '=', 'm.modalidad_id')
            ->join('clientes.deportistas as d', 'd.id', '=', 'm.deportista_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as cliente_user', 'cliente_user.id', '=', 'd.usuario_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->whereIn('m.sede_id', $sedes)
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
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '') as identificacion")
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
            ->selectRaw("{$saldoSql} as saldo_pendiente");

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(m.codigo_contrato) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.nombre_completo, cliente_user.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.identificacion, cliente_user.cedula, '')) LIKE ?", [$texto])
                    ->orWhereRaw('LOWER(plan.nombre) LIKE ?', [$texto]);
            });
        }

        if (! empty($filtros['codigo_contrato'])) {
            $query->whereRaw('LOWER(m.codigo_contrato) LIKE ?', ['%' . mb_strtolower((string) $filtros['codigo_contrato']) . '%']);
        }

        if (! empty($filtros['cliente'])) {
            $texto = '%' . mb_strtolower((string) $filtros['cliente']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw("LOWER(COALESCE(persona.nombre_completo, cliente_user.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.identificacion, cliente_user.cedula, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['plan'])) {
            $query->whereRaw('LOWER(plan.nombre) LIKE ?', ['%' . mb_strtolower((string) $filtros['plan']) . '%']);
        }

        if (! empty($filtros['modalidad'])) {
            $query->whereRaw("LOWER(COALESCE(modalidad.nombre, 'Sin modalidad')) LIKE ?", ['%' . mb_strtolower((string) $filtros['modalidad']) . '%']);
        }

        if (! empty($filtros['numero_periodo'])) {
            $query->whereRaw('CAST(mp.numero_periodo AS TEXT) LIKE ?', ['%' . trim((string) $filtros['numero_periodo']) . '%']);
        }

        if (! empty($filtros['fecha_fin'])) {
            $valor = '%' . trim((string) $filtros['fecha_fin']) . '%';
            $query->where(function ($q) use ($valor): void {
                $q->whereRaw('CAST(mp.fecha_fin AS TEXT) LIKE ?', [$valor])
                    ->orWhereRaw("TO_CHAR(mp.fecha_fin, 'DD/MM/YYYY') LIKE ?", [$valor]);
            });
        }

        if (! empty($filtros['dias_restantes'])) {
            $query->whereRaw("CAST({$diasRestantesSql} AS TEXT) LIKE ?", ['%' . trim((string) $filtros['dias_restantes']) . '%']);
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

        if (! empty($filtros['saldo'])) {
            $query->whereRaw("CAST({$saldoSql} AS TEXT) LIKE ?", ['%' . trim((string) $filtros['saldo']) . '%']);
        }

        $resumen = DB::query()
            ->fromSub(clone $query, 'reporte')
            ->selectRaw('COUNT(*) as total_por_vencer')
            ->selectRaw('SUM(CASE WHEN dias_restantes BETWEEN 0 AND 7 THEN 1 ELSE 0 END) as vence_7_dias')
            ->selectRaw('SUM(CASE WHEN dias_restantes BETWEEN 0 AND 15 THEN 1 ELSE 0 END) as vence_15_dias')
            ->selectRaw('SUM(CASE WHEN renovable = true THEN 1 ELSE 0 END) as renovables')
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) as saldo_pendiente')
            ->first();

        $paginador = $query
            ->orderBy('mp.fecha_fin')
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
                ],
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
