<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class ConciliacionCajaServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['fecha', 'caja_nombre', 'cajero', 'hora_apertura', 'hora_cierre', 'saldo_inicial', 'efectivo_cobrado', 'efectivo_esperado', 'efectivo_contado', 'diferencia', 'transferencia_cobrada', 'tarjeta_cobrada', 'deposito_cobrado', 'otros_cobrado', 'diferencia_fondo'];

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
            ])
            ->selectRaw("DATE(t.fecha_cierre) as fecha, TO_CHAR(t.fecha_apertura, 'HH24:MI') as hora_apertura, TO_CHAR(t.fecha_cierre, 'HH24:MI') as hora_cierre")
            // El fondo con que abre un turno debería ser el efectivo con que cerró el turno anterior de la
            // misma caja. Si no coincide, salió o entró dinero sin registrarse.
            ->selectRaw("(SELECT COALESCE(a.efectivo_contado, a.efectivo_esperado) FROM ventas.turnos_caja a
                WHERE a.caja_id = t.caja_id AND a.fecha_apertura < t.fecha_apertura AND a.estado = 'CERRADA'
                ORDER BY a.fecha_apertura DESC LIMIT 1) as fondo_anterior")
            ->selectRaw("t.saldo_inicial - (SELECT COALESCE(a.efectivo_contado, a.efectivo_esperado) FROM ventas.turnos_caja a
                WHERE a.caja_id = t.caja_id AND a.fecha_apertura < t.fecha_apertura AND a.estado = 'CERRADA'
                ORDER BY a.fecha_apertura DESC LIMIT 1) as diferencia_fondo")
            ->selectRaw("CASE WHEN t.requiere_arqueo AND t.conciliado_at IS NULL THEN true ELSE false END as pendiente_arqueo");

        if (! empty($filtros['busqueda'])) {
            $texto = '%' . mb_strtolower((string) $filtros['busqueda']) . '%';
            $query->where(function ($q) use ($texto): void {
                $q->whereRaw('LOWER(c.nombre) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(c.codigo) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(s.nombre) LIKE ?', [$texto])
                    ->orWhereRaw('LOWER(cajero.name) LIKE ?', [$texto]);
            });
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

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($query, $filtros, self::COLUMNAS);

        // Alertas (se cuentan antes de filtrar por alerta; al tocar una tarjeta se filtra).
        $alertas = (clone $reporte)
            ->selectRaw('SUM(CASE WHEN pendiente_arqueo THEN 1 ELSE 0 END) as pendientes')
            ->selectRaw('SUM(CASE WHEN ABS(COALESCE(diferencia, 0)) > 0.009 THEN 1 ELSE 0 END) as con_diferencia')
            ->selectRaw('COALESCE(SUM(CASE WHEN diferencia > 0 THEN diferencia ELSE 0 END), 0) as sobrantes')
            ->selectRaw('COALESCE(SUM(CASE WHEN diferencia < 0 THEN -diferencia ELSE 0 END), 0) as faltantes')
            ->selectRaw('SUM(CASE WHEN ABS(COALESCE(diferencia_fondo, 0)) > 0.009 THEN 1 ELSE 0 END) as fondo_no_cuadra')
            ->first();
        $pendientesTotales = DB::table('ventas.turnos_caja')->whereIn('sede_id', $sedes)->where('estado', 'CERRADA')
            ->where('requiere_arqueo', true)->whereNull('conciliado_at')->count();
        match ($filtros['alerta'] ?? null) {
            'PENDIENTE' => $reporte->where('pendiente_arqueo', true),
            'DIFERENCIA' => $reporte->whereRaw('ABS(COALESCE(diferencia, 0)) > 0.009'),
            'FONDO' => $reporte->whereRaw('ABS(COALESCE(diferencia_fondo, 0)) > 0.009'),
            default => null,
        };

        $resumen = (clone $reporte)
            ->selectRaw('COUNT(*) as turnos_cerrados')
            ->selectRaw('COALESCE(SUM(efectivo_esperado), 0) as efectivo_esperado')
            ->selectRaw('COALESCE(SUM(efectivo_contado), 0) as efectivo_contado')
            ->selectRaw('COALESCE(SUM(diferencia), 0) as diferencia_total')
            ->selectRaw('COALESCE(SUM(transferencia_cobrada + tarjeta_cobrada + deposito_cobrado + otros_cobrado), 0) as no_efectivo')
            ->selectRaw('SUM(CASE WHEN requiere_arqueo = true THEN 1 ELSE 0 END) as pendientes_conciliacion')
            ->selectRaw('COALESCE(SUM(efectivo_cobrado), 0) as efectivo_cobrado')
            ->selectRaw('COALESCE(SUM(transferencia_cobrada + deposito_cobrado), 0) as transferencias')
            ->first();

        $paginador = $reporte
            ->orderByDesc('fecha_cierre')
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
                    'turnos_cerrados' => (int) ($resumen->turnos_cerrados ?? 0),
                    'efectivo_esperado' => round((float) ($resumen->efectivo_esperado ?? 0), 2),
                    'efectivo_contado' => round((float) ($resumen->efectivo_contado ?? 0), 2),
                    'diferencia_total' => round((float) ($resumen->diferencia_total ?? 0), 2),
                    'no_efectivo' => round((float) ($resumen->no_efectivo ?? 0), 2),
                    'pendientes_conciliacion' => (int) ($resumen->pendientes_conciliacion ?? 0),
                    'efectivo_cobrado' => round((float) ($resumen->efectivo_cobrado ?? 0), 2),
                    'transferencias' => round((float) ($resumen->transferencias ?? 0), 2),
                    'alertas' => [
                        'pendientes' => (int) ($alertas->pendientes ?? 0),
                        'pendientes_totales' => $pendientesTotales,
                        'con_diferencia' => (int) ($alertas->con_diferencia ?? 0),
                        'sobrantes' => round((float) ($alertas->sobrantes ?? 0), 2),
                        'faltantes' => round((float) ($alertas->faltantes ?? 0), 2),
                        'fondo_no_cuadra' => (int) ($alertas->fondo_no_cuadra ?? 0),
                    ],
                ],
                'opciones_filtro' => $opcionesFiltro,
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
