<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class VentasPeriodoServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['fecha', 'venta_numero', 'cliente', 'responsable_comercial', 'total_venta', 'total_cobrado', 'saldo_pendiente'];

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

        $desde = $filtros['desde'] ?? now('America/Guayaquil')->startOfMonth()->toDateString();
        $hasta = $filtros['hasta'] ?? now('America/Guayaquil')->toDateString();

        $pagadoSql = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $saldoSql = "GREATEST(v.total - {$pagadoSql}, 0)";

        $query = DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->leftJoin('clientes.deportistas as d', 'd.id', '=', 'v.cliente_id')
            ->leftJoin('personas.personas as persona', 'persona.id', '=', 'd.persona_id')
            ->leftJoin('seguridad.users as cliente_user', 'cliente_user.id', '=', 'd.usuario_id')
            ->leftJoin('seguridad.users as responsable', 'responsable.id', '=', 'v.responsable_comercial_id')
            ->whereBetween(DB::raw('DATE(v.fecha_venta)'), [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA')
            ->selectRaw("v.id, DATE(v.fecha_venta) as fecha, COALESCE(s.nombre, 'Sin sede') as sede")
            ->addSelect('v.numero as venta_numero', 'v.tipo_venta', 'v.estado')
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.nombre_completo), ''), NULLIF(TRIM(cliente_user.name), ''), 'Consumidor final') as cliente")
            ->selectRaw("COALESCE(NULLIF(TRIM(persona.identificacion), ''), NULLIF(TRIM(cliente_user.cedula), '')) as identificacion")
            ->selectRaw("COALESCE(responsable.name, 'Sin responsable') as responsable_comercial")
            ->selectRaw("v.total as total_venta, {$pagadoSql} as total_cobrado, {$saldoSql} as saldo_pendiente");

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(v.numero) LIKE ?', [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.nombre_completo, cliente_user.name, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(persona.identificacion, cliente_user.cedula, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(v.tipo_venta, '')) LIKE ?", [$texto])
                    ->orWhereRaw("LOWER(COALESCE(responsable.name, '')) LIKE ?", [$texto]);
            });
        }

        if (! empty($filtros['tipo_venta'])) {
            $valores = is_array($filtros['tipo_venta']) ? $filtros['tipo_venta'] : [$filtros['tipo_venta']];
            $query->whereIn('v.tipo_venta', array_filter($valores));
        }

        if (! empty($filtros['estado'])) {
            $valores = is_array($filtros['estado']) ? $filtros['estado'] : [$filtros['estado']];
            $query->whereIn('v.estado', array_filter($valores));
        }

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(*) as transacciones')
            ->selectRaw('COALESCE(SUM(total_venta), 0) as total_ventas')
            ->selectRaw('COALESCE(SUM(total_cobrado), 0) as total_cobrado')
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) as saldo_pendiente')
            ->first();

        $paginador = $reporte
            ->orderByDesc('fecha')
            ->orderByDesc('id')
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
                    'transacciones' => (int) ($resumen->transacciones ?? 0),
                    'total_ventas' => round((float) ($resumen->total_ventas ?? 0), 2),
                    'total_cobrado' => round((float) ($resumen->total_cobrado ?? 0), 2),
                    'saldo_pendiente' => round((float) ($resumen->saldo_pendiente ?? 0), 2),
                ],
                'opciones_filtro' => $opcionesFiltro,
                'catalogos' => [
                    'sedes' => DB::table('institucional.sedes')
                        ->whereIn('id_sede', $sedesPermitidas)
                        ->where('activo', true)
                        ->orderBy('nombre')
                        ->get(['id_sede as id', 'nombre']),
                    'tipos_venta' => DB::table('ventas.ventas')
                        ->whereNotNull('tipo_venta')
                        ->distinct()
                        ->orderBy('tipo_venta')
                        ->pluck('tipo_venta')
                        ->values(),
                    'estados' => DB::table('ventas.ventas')
                        ->where('estado', '<>', 'ANULADA')
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
