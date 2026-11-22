<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class CarteraVencidaServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['venta_numero', 'cliente', 'paga', 'telefono', 'contrato', 'fecha_vencimiento', 'dias_vencidos', 'saldo_pendiente', 'sede'];

    /** Tramos de atraso: se cobran primero las deudas más viejas. */
    private const TRAMOS = ['1-7' => [1, 7], '8-30' => [8, 30], '31+' => [31, 100000]];

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
            ->leftJoin('clientes.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as cliente_user', 'cliente_user.id', '=', 'd.usuario_id')
            ->leftJoin('seguridad.users as responsable', 'responsable.id', '=', 'cc.responsable_id')
            // Quien paga: el tutor responsable de pago activo, si el cliente tiene uno.
            ->leftJoin('personas.personas as tutor', 'tutor.id', '=', DB::raw('(SELECT r.representante_persona_id FROM clientes.deportista_representantes r
                WHERE r.deportista_id = d.id AND r.activo AND r.responsable_pago ORDER BY r.es_principal DESC, r.id LIMIT 1)'))
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->whereDate('cc.fecha_vencimiento', '<', now('America/Guayaquil')->toDateString())
            ->where('v.estado', '<>', 'ANULADA')
            ->whereRaw("{$saldoSql} > 0")
            ->selectRaw("cc.id, v.numero as venta_numero, COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Consumidor final') as cliente")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '')) as identificacion")
            ->selectRaw("cc.fecha_vencimiento, CURRENT_DATE - cc.fecha_vencimiento as dias_vencidos")
            ->selectRaw("v.total as total_venta, {$pagadoSql} as total_pagado, {$saldoSql} as saldo_pendiente")
            ->selectRaw("COALESCE(responsable.name, 'Sin asignar') as responsable")
            ->selectRaw("COALESCE(NULLIF(TRIM(tutor.nombre_completo), '') || ' (tutor)', COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Consumidor final')) as paga")
            ->selectRaw("COALESCE(NULLIF(TRIM(tutor.telefono), ''), NULLIF(TRIM(persona.telefono), ''), NULLIF(TRIM(d.telefono), '')) as telefono")
            ->selectRaw("COALESCE(tutor.id, persona.id) as deudor_id")
            ->selectRaw("COALESCE(m.codigo_contrato, v.numero) as contrato")
            ->selectRaw("(SELECT TO_CHAR(mp.fecha_inicio, 'DD/MM/YYYY') || ' al ' || TO_CHAR(mp.fecha_fin, 'DD/MM/YYYY') FROM membresias.membresia_periodos mp WHERE mp.venta_id = v.id ORDER BY mp.id DESC LIMIT 1) as periodo")
            ->selectRaw('v.concepto')
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

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        // Cuentas y saldo por tramo de atraso, antes de filtrar por tramo (las tarjetas los muestran todos).
        $tramos = [];
        foreach (self::TRAMOS as $clave => [$desde, $hasta]) {
            $fila = (clone $reporte)->whereBetween('dias_vencidos', [$desde, $hasta])
                ->selectRaw('COUNT(*) as cuentas, COALESCE(SUM(saldo_pendiente), 0) as saldo')->first();
            $tramos[$clave] = ['cuentas' => (int) $fila->cuentas, 'saldo' => round((float) $fila->saldo, 2)];
        }
        if (! empty($filtros['tramo']) && isset(self::TRAMOS[$filtros['tramo']])) {
            $reporte->whereBetween('dias_vencidos', self::TRAMOS[$filtros['tramo']]);
        }

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(DISTINCT deudor_id) as deudores')
            ->selectRaw('COUNT(*) as cuentas_vencidas')
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) as saldo_vencido')
            ->selectRaw('COALESCE(AVG(dias_vencidos), 0) as promedio_dias_vencidos')
            ->first();

        $paginador = $reporte
            ->orderBy('fecha_vencimiento')
            ->orderBy('paga')
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
                    'cuentas_vencidas' => (int) ($resumen->cuentas_vencidas ?? 0),
                    'saldo_vencido' => round((float) ($resumen->saldo_vencido ?? 0), 2),
                    'promedio_dias_vencidos' => round((float) ($resumen->promedio_dias_vencidos ?? 0), 1),
                    'deudores' => (int) ($resumen->deudores ?? 0),
                    'tramos' => $tramos,
                ],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')->whereIn('id_sede', $sedesPermitidas)->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
                    'prioridades' => ['BAJA', 'NORMAL', 'ALTA', 'URGENTE'],
                ],
            ],
        ];
    }
}
