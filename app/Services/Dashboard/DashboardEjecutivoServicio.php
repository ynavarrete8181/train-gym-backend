<?php

namespace App\Services\Dashboard;

use App\Services\Seguridad\AlcanceOperativoService;
use App\Services\Ventas\Reportes\CarteraVencidaServicio;
use App\Services\Ventas\Reportes\ConciliacionCajaServicio;
use App\Services\Ventas\Reportes\MembresiasNuevasRenovacionesServicio;
use App\Services\Ventas\Reportes\MembresiasPorVencerServicio;
use App\Services\Ventas\Reportes\ProductosServiciosVendidosServicio;
use App\Services\Ventas\Reportes\ResumenComercialServicio;
use App\Services\Ventas\Reportes\VentasResponsableServicio;
use Illuminate\Support\Facades\DB;

class DashboardEjecutivoServicio
{
    public function __construct(
        private readonly AlcanceOperativoService $alcance,
        private readonly ResumenComercialServicio $resumenComercial,
        private readonly CarteraVencidaServicio $carteraVencida,
        private readonly MembresiasNuevasRenovacionesServicio $membresiasMovimientos,
        private readonly MembresiasPorVencerServicio $membresiasPorVencer,
        private readonly ConciliacionCajaServicio $conciliacionCaja,
        private readonly VentasResponsableServicio $ventasResponsable,
        private readonly ProductosServiciosVendidosServicio $productosServicios,
    ) {}

    public function consultar(array $filtros, int $usuarioId): array
    {
        $desde = $filtros['desde'] ?? now()->startOfMonth()->toDateString();
        $hasta = $filtros['hasta'] ?? now()->toDateString();
        $sedeId = $filtros['sede_id'] ?? [];

        $base = [
            'desde' => $desde,
            'hasta' => $hasta,
            'sede_id' => $sedeId,
        ];

        $comercial = $this->resumenComercial->consultar($base, $usuarioId);
        $cartera = $this->carteraVencida->consultar(array_merge($base, ['page' => 1, 'per_page' => 5]), $usuarioId);
        $movimientos = $this->membresiasMovimientos->consultar(array_merge($base, ['page' => 1, 'per_page' => 5]), $usuarioId);
        $porVencer = $this->membresiasPorVencer->consultar([
            'vence_desde' => now()->toDateString(),
            'vence_hasta' => now()->addDays(30)->toDateString(),
            'sede_id' => $sedeId,
            'page' => 1,
            'per_page' => 5,
        ], $usuarioId);
        $caja = $this->conciliacionCaja->consultar(array_merge($base, ['page' => 1, 'per_page' => 5]), $usuarioId);
        $responsables = $this->ventasResponsable->consultar(array_merge($base, ['page' => 1, 'per_page' => 5]), $usuarioId);
        $productos = $this->productosServicios->consultar(array_merge($base, ['page' => 1, 'per_page' => 5]), $usuarioId);

        $sedes = $this->resolverSedes($sedeId, $usuarioId);
        $membresiasActivas = DB::table('membresias.membresias')
            ->whereIn('sede_id', $sedes)
            ->whereRaw("UPPER(COALESCE(estado, '')) = 'ACTIVA'")
            ->count();

        $turnosAbiertos = DB::table('ventas.turnos_caja')
            ->whereIn('sede_id', $sedes)
            ->where('estado', 'ABIERTA')
            ->count();

        $indicadoresComerciales = $comercial['indicadores'] ?? [];
        $resumenCartera = $cartera['meta']['resumen'] ?? [];
        $resumenMovimientos = $movimientos['meta']['resumen'] ?? [];
        $resumenVencimientos = $porVencer['meta']['resumen'] ?? [];
        $resumenCaja = $caja['meta']['resumen'] ?? [];

        $totalVentas = (float) ($indicadoresComerciales['total_ventas'] ?? 0);
        $transacciones = (int) ($indicadoresComerciales['transacciones'] ?? 0);

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'indicadores' => [
                'ventas' => round($totalVentas, 2),
                'cobrado' => round((float) ($indicadoresComerciales['total_cobrado'] ?? 0), 2),
                'transacciones' => $transacciones,
                'ticket_promedio' => $transacciones > 0 ? round($totalVentas / $transacciones, 2) : 0,
                'variacion_ventas' => round((float) ($indicadoresComerciales['variacion_ventas'] ?? 0), 2),
                'variacion_cobros' => round((float) ($indicadoresComerciales['variacion_cobros'] ?? 0), 2),
                'membresias_activas' => (int) $membresiasActivas,
                'membresias_nuevas' => (int) ($resumenMovimientos['nuevas'] ?? 0),
                'renovaciones' => (int) ($resumenMovimientos['renovaciones'] ?? 0),
                'membresias_por_vencer' => (int) ($resumenVencimientos['total_por_vencer'] ?? 0),
                'cartera_vencida' => round((float) ($resumenCartera['saldo_vencido'] ?? 0), 2),
                'cuentas_vencidas' => (int) ($resumenCartera['cuentas_vencidas'] ?? 0),
                'turnos_abiertos' => (int) $turnosAbiertos,
                'conciliaciones_pendientes' => (int) ($resumenCaja['pendientes_conciliacion'] ?? 0),
                'diferencia_caja' => round((float) ($resumenCaja['diferencia_total'] ?? 0), 2),
            ],
            'ventas_por_sede' => collect($comercial['por_sede'] ?? [])->take(8)->values(),
            'top_responsables' => collect($responsables['datos'] ?? [])->take(5)->values(),
            'top_productos_servicios' => collect($productos['datos'] ?? [])->take(5)->values(),
            'cartera_critica' => collect($cartera['datos'] ?? [])->take(5)->values(),
            'membresias_proximas_vencer' => collect($porVencer['datos'] ?? [])->take(5)->values(),
            'alertas' => $this->alertas(
                $resumenCartera,
                $resumenVencimientos,
                $resumenCaja,
                $turnosAbiertos,
            ),
            'catalogos' => [
                'sedes' => $comercial['catalogos']['sedes'] ?? [],
            ],
        ];
    }

    private function resolverSedes(mixed $sedeId, int $usuarioId): array
    {
        $permitidas = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        $solicitadas = collect(is_array($sedeId) ? $sedeId : [$sedeId])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($permitidas)
            ->values()
            ->all();

        return $solicitadas ?: $permitidas;
    }

    private function alertas(array $cartera, array $vencimientos, array $caja, int $turnosAbiertos): array
    {
        $alertas = [];

        if ((float) ($cartera['saldo_vencido'] ?? 0) > 0) {
            $alertas[] = [
                'tipo' => 'CARTERA',
                'nivel' => 'warning',
                'titulo' => 'Cartera vencida',
                'detalle' => sprintf(
                    '%d cuenta(s) mantienen un saldo vencido de $%s.',
                    (int) ($cartera['cuentas_vencidas'] ?? 0),
                    number_format((float) ($cartera['saldo_vencido'] ?? 0), 2, '.', ',')
                ),
            ];
        }

        if ((int) ($vencimientos['vence_7_dias'] ?? 0) > 0) {
            $alertas[] = [
                'tipo' => 'MEMBRESIAS',
                'nivel' => 'info',
                'titulo' => 'Membresías próximas a vencer',
                'detalle' => (int) ($vencimientos['vence_7_dias'] ?? 0) . ' membresía(s) vencen dentro de los próximos 7 días.',
            ];
        }

        if ((int) ($caja['pendientes_conciliacion'] ?? 0) > 0) {
            $alertas[] = [
                'tipo' => 'CAJA',
                'nivel' => 'error',
                'titulo' => 'Conciliaciones pendientes',
                'detalle' => (int) ($caja['pendientes_conciliacion'] ?? 0) . ' cierre(s) de caja requieren conciliación.',
            ];
        }

        if (abs((float) ($caja['diferencia_total'] ?? 0)) > 0.00001) {
            $alertas[] = [
                'tipo' => 'CAJA',
                'nivel' => 'warning',
                'titulo' => 'Diferencias de caja',
                'detalle' => 'La diferencia acumulada del período es $' . number_format((float) $caja['diferencia_total'], 2, '.', ',') . '.',
            ];
        }

        if ($turnosAbiertos > 0) {
            $alertas[] = [
                'tipo' => 'CAJA',
                'nivel' => 'success',
                'titulo' => 'Turnos de caja abiertos',
                'detalle' => $turnosAbiertos . ' turno(s) permanecen abiertos actualmente.',
            ];
        }

        return $alertas;
    }
}
