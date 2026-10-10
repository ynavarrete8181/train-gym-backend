<?php

namespace App\Services\Ventas\Reportes;

use App\Services\Seguridad\AlcanceOperativoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ResumenComercialServicio
{
    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function consultar(array $filtros, int $usuarioId): array
    {
        $sedes = $this->resolverSedes($filtros, $usuarioId);
        $desde = Carbon::parse($filtros['desde'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $hasta = Carbon::parse($filtros['hasta'] ?? now()->toDateString())->endOfDay();
        $dias = $desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay()) + 1;
        $hastaAnterior = $desde->copy()->subDay()->endOfDay();
        $desdeAnterior = $hastaAnterior->copy()->subDays($dias - 1)->startOfDay();

        $ventasActuales = $this->ventasBase($sedes, $desde, $hasta);
        $ventasAnteriores = $this->ventasBase($sedes, $desdeAnterior, $hastaAnterior);
        $pagosActuales = $this->pagosBase($sedes, $desde, $hasta);
        $pagosAnteriores = $this->pagosBase($sedes, $desdeAnterior, $hastaAnterior);

        $totalVentas = (float) (clone $ventasActuales)->sum('v.total');
        $transacciones = (clone $ventasActuales)->count('v.id');
        $totalCobrado = (float) (clone $pagosActuales)->sum('p.monto');
        $ventasAnterior = (float) (clone $ventasAnteriores)->sum('v.total');
        $cobradoAnterior = (float) (clone $pagosAnteriores)->sum('p.monto');

        $porSede = $this->ventasBase($sedes, $desde, $hasta)
            ->leftJoin('institucional.sedes as s', 's.id_sede', '=', DB::raw('COALESCE(c.sede_id, m.sede_id)'))
            ->selectRaw("COALESCE(s.nombre, 'Sin sede') as sede")
            ->selectRaw('COUNT(v.id) as transacciones')
            ->selectRaw('SUM(v.total) as total_ventas')
            ->groupByRaw("COALESCE(s.nombre, 'Sin sede')")
            ->orderByDesc('total_ventas')
            ->get()
            ->map(fn ($fila) => [
                'sede' => $fila->sede,
                'transacciones' => (int) $fila->transacciones,
                'total_ventas' => round((float) $fila->total_ventas, 2),
            ])
            ->values();

        return [
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'comparativo' => [
                'desde' => $desdeAnterior->toDateString(),
                'hasta' => $hastaAnterior->toDateString(),
            ],
            'indicadores' => [
                'total_ventas' => round($totalVentas, 2),
                'transacciones' => $transacciones,
                'total_cobrado' => round($totalCobrado, 2),
                'variacion_ventas' => $this->variacion($totalVentas, $ventasAnterior),
                'variacion_cobros' => $this->variacion($totalCobrado, $cobradoAnterior),
            ],
            'por_sede' => $porSede,
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
