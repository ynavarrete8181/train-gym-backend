<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class CarteraVencidaServicio
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

        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto),0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(v.total - {$pagadoSql}, 0)";

        $query = DB::table('cuentas_cobrar.cuentas as cc')
            ->join('ventas.ventas as v', 'v.id', '=', 'cc.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->leftJoin('gimnasio.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as cliente_user', 'cliente_user.id', '=', 'd.usuario_id')
            ->leftJoin('seguridad.users as responsable', 'responsable.id', '=', 'cc.responsable_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->whereDate('cc.fecha_vencimiento', '<', now()->toDateString())
            ->where('v.estado', '<>', 'ANULADA')
            ->whereRaw("{$saldoSql} > 0")
            ->selectRaw("cc.id, v.numero as venta_numero, COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Consumidor final') as cliente")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '') as identificacion")
            ->selectRaw("cc.fecha_vencimiento, CURRENT_DATE - cc.fecha_vencimiento as dias_vencidos")
            ->selectRaw("v.total as total_venta, {$pagadoSql} as total_pagado, {$saldoSql} as saldo_pendiente")
            ->selectRaw("COALESCE(responsable.name, 'Sin asignar') as responsable")
            ->addSelect('cc.prioridad');

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(v.numero) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.nombre_completo, cliente_user.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.identificacion, cliente_user.cedula, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(responsable.name, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['prioridad'])) {
            $valores = is_array($filtros['prioridad']) ? $filtros['prioridad'] : [$filtros['prioridad']];
            $query->whereIn('cc.prioridad', array_filter($valores));
        }

        $resumenQuery = clone $query;
        $resumenFilas = $resumenQuery->get();

        $paginador = $query
            ->orderBy('cc.fecha_vencimiento')
            ->orderByDesc('saldo_pendiente')
            ->paginate($filtros['per_page'] ?? 10, ['*'], 'page', $filtros['page'] ?? 1);

        return [
            'datos' => $paginador->items(),
            'meta' => [
                'pagina_actual' => $paginador->currentPage(),
                'por_pagina' => $paginador->perPage(),
                'total' => $paginador->total(),
                'ultima_pagina' => $paginador->lastPage(),
                'resumen' => [
                    'cuentas_vencidas' => $resumenFilas->count(),
                    'saldo_vencido' => round((float) $resumenFilas->sum('saldo_pendiente'), 2),
                    'promedio_dias_vencidos' => $resumenFilas->count() ? round((float) $resumenFilas->avg('dias_vencidos'), 1) : 0,
                ],
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                    'prioridades' => ['BAJA', 'NORMAL', 'ALTA', 'URGENTE'],
                ],
            ],
        ];
    }
}
