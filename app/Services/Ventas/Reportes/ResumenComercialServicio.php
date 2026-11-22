<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ResumenComercialServicio
{
    use FiltraColumnasReporte;

    private const COLUMNAS = ['transacciones', 'total_ventas', 'descuentos', 'por_cobrar'];

    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, int $usuarioId): array
    {
        $sedes = $this->resolverSedes($filtros, $usuarioId);
        $desde = Carbon::parse($filtros['desde'] ?? now('America/Guayaquil')->startOfMonth()->toDateString())->startOfDay();
        $hasta = Carbon::parse($filtros['hasta'] ?? now('America/Guayaquil')->toDateString())->endOfDay();
        $dias = $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
        $hastaAnterior = $desde->copy()->subDay()->endOfDay();
        $desdeAnterior = $hastaAnterior->copy()->subDays($dias - 1)->startOfDay();
        $pagado = "(SELECT COALESCE(SUM(pg.monto), 0) FROM ventas.pagos pg WHERE pg.venta_id = v.id AND pg.estado = 'CONFIRMADO')";
        $sedeExpr = 'COALESCE(c.sede_id, m.sede_id)';

        // Ventas = lo facturado en el período (pagado o no). Cobrado = el dinero que entró en el
        // período, sea de ventas de estas fechas o anteriores. Por cobrar = lo que aún se debe de
        // las ventas del período.
        $ventas = $this->ventasBase($sedes, $desde, $hasta)
            ->selectRaw('COUNT(v.id) as transacciones, COALESCE(SUM(v.total), 0) as total_ventas')
            ->selectRaw('COALESCE(SUM(v.descuento), 0) as descuentos')
            ->selectRaw("COALESCE(SUM(GREATEST(v.total - {$pagado}, 0)), 0) as por_cobrar")
            ->selectRaw("COALESCE(SUM(v.total) FILTER (WHERE v.tipo_venta = 'MEMBRESIA'), 0) as membresias")
            ->selectRaw("COALESCE(SUM(v.total) FILTER (WHERE v.tipo_venta <> 'MEMBRESIA'), 0) as productos_servicios")
            ->first();
        $totalCobrado = (float) $this->pagosBase($sedes, $desde, $hasta)->sum('p.monto');
        $ventasAnterior = (float) $this->ventasBase($sedes, $desdeAnterior, $hastaAnterior)->sum('v.total');
        $cobradoAnterior = (float) $this->pagosBase($sedes, $desdeAnterior, $hastaAnterior)->sum('p.monto');

        // Solo se compara si el sistema ya registraba ventas durante todo el período anterior
        // (se usa la fecha de registro, no la de la venta: hubo ventas cargadas con fecha atrasada).
        // Si no, el porcentaje no dice nada (por ejemplo, +14.900 % frente a $15).
        $primeraVenta = DB::table('ventas.ventas')->min('created_at');
        $comparable = $primeraVenta && Carbon::parse($primeraVenta)->startOfDay()->lte($desdeAnterior);

        $porSedeQuery = $this->ventasBase($sedes, $desde, $hasta)
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw($sedeExpr))
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw('COUNT(v.id) as transacciones')
            ->selectRaw('SUM(v.total) as total_ventas')
            ->selectRaw('SUM(v.descuento) as descuentos')
            ->selectRaw("SUM(GREATEST(v.total - {$pagado}, 0)) as por_cobrar")
            ->groupByRaw("COALESCE(s.nombre, 'Sin sede')");

        [$reporte, $opcionesFiltro] = $this->filtrarColumnas($porSedeQuery, $filtros, self::COLUMNAS);

        $cobradoPorSede = $this->pagosBase($sedes, $desde, $hasta)
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw($sedeExpr))
            ->groupByRaw("COALESCE(s.nombre, 'Sin sede')")
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede, SUM(p.monto) as cobrado")
            ->pluck('cobrado', 'sede');

        $porSede = $reporte->orderByDesc('total_ventas')->get()
            ->map(fn ($fila) => [
                'sede' => $fila->sede,
                'transacciones' => (int) $fila->transacciones,
                'total_ventas' => round((float) $fila->total_ventas, 2),
                'descuentos' => round((float) $fila->descuentos, 2),
                'total_cobrado' => round((float) ($cobradoPorSede[$fila->sede] ?? 0), 2),
                'por_cobrar' => round((float) $fila->por_cobrar, 2),
            ]);
        // Una sede que solo cobró deudas anteriores también aparece (si no se filtra por columnas).
        if (empty(array_filter($filtros['columnas'] ?? []))) {
            foreach ($cobradoPorSede as $sede => $cobrado) {
                if (! $porSede->contains('sede', $sede)) {
                    $porSede->push(['sede' => $sede, 'transacciones' => 0, 'total_ventas' => 0.0, 'descuentos' => 0.0,
                        'total_cobrado' => round((float) $cobrado, 2), 'por_cobrar' => 0.0]);
                }
            }
        }

        return [
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'comparativo' => [
                'desde' => $desdeAnterior->toDateString(),
                'hasta' => $hastaAnterior->toDateString(),
                'disponible' => $comparable,
                'total_ventas' => round($ventasAnterior, 2),
                'total_cobrado' => round($cobradoAnterior, 2),
            ],
            'indicadores' => [
                'total_ventas' => round((float) $ventas->total_ventas, 2),
                'transacciones' => (int) $ventas->transacciones,
                'total_cobrado' => round($totalCobrado, 2),
                'por_cobrar' => round((float) $ventas->por_cobrar, 2),
                'descuentos' => round((float) $ventas->descuentos, 2),
                'membresias' => round((float) $ventas->membresias, 2),
                'productos_servicios' => round((float) $ventas->productos_servicios, 2),
                'variacion_ventas' => $comparable ? $this->variacion((float) $ventas->total_ventas, $ventasAnterior) : null,
                'variacion_cobros' => $comparable ? $this->variacion($totalCobrado, $cobradoAnterior) : null,
            ],
            'por_sede' => $porSede->values(),
            'opciones_filtro' => $opcionesFiltro,
            'catalogos' => [
                'sedes' => DB::table('institucional.sedes')
                    ->whereIn('id_sede', $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja'))
                    ->where('activo', true)
                    ->orderBy('nombre')
                    ->get(['id_sede as id', 'nombre']),
            ],
        ];
    }

    private function resolverSedes(array $filtros, int $usuarioId): array
    {
        $permitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');
        $solicitadas = collect(is_array($filtros['sede_id'] ?? null) ? $filtros['sede_id'] : [$filtros['sede_id'] ?? null])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($permitidas)
            ->values()
            ->all();

        return $solicitadas ?: $permitidas;
    }

    private function ventasBase(array $sedes, Carbon $desde, Carbon $hasta)
    {
        return DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes)
            ->where('v.estado', '<>', 'ANULADA');
    }

    private function pagosBase(array $sedes, Carbon $desde, Carbon $hasta)
    {
        return DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'p.caja_id')
            ->leftJoin('membresias.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->where('p.estado', 'CONFIRMADO')
            ->whereBetween('p.fecha_pago', [$desde, $hasta])
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);
    }

    private function variacion(float $actual, float $anterior): float
    {
        if (abs($anterior) < 0.00001) {
            return $actual > 0 ? 100.0 : 0.0;
        }

        return round((($actual - $anterior) / $anterior) * 100, 2);
    }
}
