<?php

namespace App\Services\Ventas;

use App\Services\Seguridad\AlcanceOperativoService;
use Illuminate\Support\Facades\DB;

class VentaFiltroServicio
{
    public function __construct(private readonly AlcanceOperativoService $alcance)
    {
    }

    public function opciones(?int $usuarioId = null): array
    {
        $sedes = $this->alcance->sedesPermitidas($usuarioId, 'maneja_caja');

        $baseVentas = fn () => DB::table('ventas.ventas as v')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);

        $basePagos = fn () => DB::table('ventas.pagos as p')
            ->join('ventas.ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('ventas.cajas as c', 'c.id', '=', 'v.caja_id')
            ->leftJoin('gimnasio.membresias as m', 'm.id', '=', 'v.membresia_id')
            ->whereIn(DB::raw('COALESCE(c.sede_id, m.sede_id)'), $sedes);

        return [
            'caja' => DB::table('ventas.cajas')
                ->whereIn('sede_id', $sedes)
                ->distinct()
                ->orderBy('nombre')
                ->pluck('nombre')
                ->values(),
            'cliente' => DB::table('seguridad.users')
                ->distinct()
                ->orderBy('name')
                ->pluck('name')
                ->values(),
            'numero' => $baseVentas()
                ->select('v.numero')
                ->distinct()
                ->orderBy('v.numero')
                ->pluck('v.numero')
                ->values(),
            'tipo' => $baseVentas()
                ->select('v.tipo_venta')
                ->distinct()
                ->orderBy('v.tipo_venta')
                ->pluck('v.tipo_venta')
                ->values(),
            'metodo' => $basePagos()
                ->select('p.metodo_pago')
                ->distinct()
                ->orderBy('p.metodo_pago')
                ->pluck('p.metodo_pago')
                ->values(),
            'estado_venta' => DB::table('configuracion.estados_catalogo')
                ->where('entidad', 'VENTA')
                ->where('activo', true)
                ->orderBy('orden')
                ->pluck('valor_interno')
                ->values(),
            'estado_pago' => DB::table('configuracion.estados_catalogo')
                ->where('entidad', 'PAGO')
                ->where('activo', true)
                ->orderBy('orden')
                ->pluck('valor_interno')
                ->values(),
        ];
    }
}
