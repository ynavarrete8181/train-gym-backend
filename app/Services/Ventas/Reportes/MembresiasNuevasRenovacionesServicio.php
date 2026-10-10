<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class MembresiasNuevasRenovacionesServicio
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

        $fechaMovimientoSql = 'COALESCE(DATE(mp.generado_at), DATE(mp.created_at), mp.fecha_inicio)';
        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = mp.venta_id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(COALESCE(v.total, mp.precio, 0) - {$pagadoSql}, 0)";
        $tipoMovimientoSql = "CASE WHEN mp.numero_periodo = 1 THEN 'NUEVA' ELSE 'RENOVACION' END";

        $query = DB::table('membresias.membresia_periodos as mp')
            ->join('membresias.membresias as m', 'm.id', '=', 'mp.membresia_id')
            ->join('membresias.planes as plan', 'plan.id', '=', 'm.plan_id')
            ->leftJoin('membresias.plan_modalidades as modalidad', 'modalidad.id', '=', 'm.modalidad_id')
            ->join('clientes.deportistas as d', 'd.id', '=', 'm.deportista_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as cliente_user', 'cliente_user.id', '=', 'd.usuario_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', 'm.sede_id')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'mp.venta_id')
            ->whereBetween(DB::raw($fechaMovimientoSql), [$desde, $hasta])
            ->whereIn('m.sede_id', $sedes)
            ->selectRaw("mp.id, {$fechaMovimientoSql} as fecha_movimiento")
            ->selectRaw("{$tipoMovimientoSql} as tipo_movimiento")
            ->addSelect(
                'm.codigo_contrato',
                'm.sede_id',
                'mp.numero_periodo',
                'mp.fecha_inicio',
                'mp.fecha_fin',
                'mp.precio',
                'mp.estado as estado_periodo',
                'mp.venta_id',
                'v.numero as venta_numero',
                'v.estado as venta_estado'
            )
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Cliente sin nombre') as cliente")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '') as identificacion")
            ->selectRaw('plan.nombre as plan')
            ->selectRaw("COALESCE(modalidad.nombre, 'Sin modalidad') as modalidad")
            ->selectRaw("{$pagadoSql} as total_cobrado")
            ->selectRaw("{$saldoSql} as saldo_pendiente");

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(m.codigo_contrato) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.nombre_completo, cliente_user.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.identificacion, cliente_user.cedula, '')) LIKE ?", [$texto])
                    ->orWhereRaw('LOWER(plan.nombre) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(v.numero, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['fecha_movimiento'])) {
            $valor = '%' . trim((string) $filtros['fecha_movimiento']) . '%';
            $query->where(function ($q) use ($valor, $fechaMovimientoSql): void {
                $q->whereRaw("CAST({$fechaMovimientoSql} AS TEXT) LIKE ?", [$valor])
                    ->orWhereRaw("TO_CHAR({$fechaMovimientoSql}, 'DD/MM/YYYY') LIKE ?", [$valor]);
            });
        }

        if (! empty($filtros['tipo_movimiento'])) {
            $valores = is_array($filtros['tipo_movimiento']) ? $filtros['tipo_movimiento'] : [$filtros['tipo_movimiento']];
            $query->whereIn(DB::raw($tipoMovimientoSql), array_filter($valores));
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

        if (! empty($filtros['estado_periodo'])) {
            $valores = is_array($filtros['estado_periodo']) ? $filtros['estado_periodo'] : [$filtros['estado_periodo']];
            $query->whereIn('mp.estado', array_filter($valores));
        }

        if (! empty($filtros['precio'])) {
            $query->whereRaw('CAST(mp.precio AS TEXT) LIKE ?', ['%' . trim((string) $filtros['precio']) . '%']);
        }

        if (! empty($filtros['cobrado'])) {
            $query->whereRaw("CAST({$pagadoSql} AS TEXT) LIKE ?", ['%' . trim((string) $filtros['cobrado']) . '%']);
        }

        if (! empty($filtros['saldo'])) {
            $query->whereRaw("CAST({$saldoSql} AS TEXT) LIKE ?", ['%' . trim((string) $filtros['saldo']) . '%']);
        }

        $resumen = DB::query()
            ->fromSub(clone $query, 'reporte')
            ->selectRaw("SUM(CASE WHEN tipo_movimiento = 'NUEVA' THEN 1 ELSE 0 END) as nuevas")
            ->selectRaw("SUM(CASE WHEN tipo_movimiento = 'RENOVACION' THEN 1 ELSE 0 END) as renovaciones")
            ->selectRaw('COUNT(*) as total_periodos')
            ->selectRaw('COALESCE(SUM(precio), 0) as total_facturado')
            ->selectRaw('COALESCE(SUM(total_cobrado), 0) as total_cobrado')
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) as saldo_pendiente')
            ->first();

        $paginador = $query
            ->orderByDesc(DB::raw($fechaMovimientoSql))
            ->orderByDesc('mp.id')
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
                    'nuevas' => (int) ($resumen->nuevas ?? 0),
                    'renovaciones' => (int) ($resumen->renovaciones ?? 0),
                    'total_periodos' => (int) ($resumen->total_periodos ?? 0),
                    'total_facturado' => round((float) ($resumen->total_facturado ?? 0), 2),
                    'total_cobrado' => round((float) ($resumen->total_cobrado ?? 0), 2),
                    'saldo_pendiente' => round((float) ($resumen->saldo_pendiente ?? 0), 2),
                ],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'tipos_movimiento' => [
                        ['value' => 'NUEVA', 'label' => 'Nueva'],
                        ['value' => 'RENOVACION', 'label' => 'Renovación'],
                    ],
                    'estados_periodo' => DB::table('membresias.membresia_periodos')
                        ->whereNotNull('estado')
                        ->distinct()
                        ->orderBy('estado')
                        ->pluck('estado')
                        ->values(),
                ],
            ],
        ];
    }
}
