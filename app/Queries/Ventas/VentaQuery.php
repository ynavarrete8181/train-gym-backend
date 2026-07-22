<?php

namespace App\Queries\Ventas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VentaQuery
{
    private ?array $ventaDetalleColumns = null;

    private function getVentaDetalleColumns(): array
    {
        if ($this->ventaDetalleColumns !== null) {
            return $this->ventaDetalleColumns;
        }

        try {
            $this->ventaDetalleColumns = Schema::getColumnListing('ventas.venta_detalles');
        } catch (\Throwable) {
            $this->ventaDetalleColumns = [];
        }

        return $this->ventaDetalleColumns;
    }

    private function ventaDetalleHasColumn(string $column): bool
    {
        return in_array($column, $this->getVentaDetalleColumns(), true);
    }

    private function buildDetalleSubquery(): string
    {
        $hasMembresiaId = $this->ventaDetalleHasColumn('membresia_id');
        $hasTipoDetalle = $this->ventaDetalleHasColumn('tipo_detalle');
        $hasDescripcion = $this->ventaDetalleHasColumn('descripcion');

        $membresiaIdExpr = $hasMembresiaId ? 'vd.membresia_id' : 'NULL';
        $tipoDetalleExpr = $hasTipoDetalle
            ? "COALESCE(vd.tipo_detalle, CASE WHEN {$membresiaIdExpr} IS NOT NULL THEN 'MEMBRESIA' ELSE 'PRODUCTO' END)"
            : "CASE WHEN {$membresiaIdExpr} IS NOT NULL THEN 'MEMBRESIA' ELSE 'PRODUCTO' END";
        $descripcionExpr = $hasDescripcion ? 'vd.descripcion' : 'NULL';
        $membershipJoin = $hasMembresiaId
            ? 'LEFT JOIN socios.membresias mem ON mem.id = vd.membresia_id'
            : '';
        $nombreExpr = $hasMembresiaId
            ? "COALESCE(prod.nombre, mem.nombre, {$descripcionExpr}, 'Detalle')"
            : "COALESCE(prod.nombre, {$descripcionExpr}, 'Detalle')";

        return "
            (
                SELECT json_agg(json_build_object(
                    'id', vd.id,
                    'producto_id', vd.producto_id,
                    'membresia_id', {$membresiaIdExpr},
                    'tipo_detalle', {$tipoDetalleExpr},
                    'descripcion', {$descripcionExpr},
                    'nombre', {$nombreExpr},
                    'cantidad', vd.cantidad,
                    'precio_unitario', vd.precio_unitario,
                    'subtotal', vd.subtotal
                ))
                FROM ventas.venta_detalles vd
                LEFT JOIN inventario.productos prod ON prod.id = vd.producto_id
                {$membershipJoin}
                WHERE vd.venta_id = v.id
            ) as detalles
        ";
    }

    private function hydrateVenta(object $venta): object
    {
        $venta->metadata = is_string($venta->metadata ?? null)
            ? (json_decode($venta->metadata, true) ?: [])
            : ($venta->metadata ?? []);

        $venta->detalles = $venta->detalles ? json_decode($venta->detalles, true) : [];

        $tieneDetalleMembresia = collect($venta->detalles)->contains(function ($detalle) use ($venta) {
            return (
                (!empty($detalle['membresia_id']) && (string) $detalle['membresia_id'] === (string) $venta->membresia_id)
                || strtoupper((string) ($detalle['tipo_detalle'] ?? '')) === 'MEMBRESIA'
            );
        });

        if (!$tieneDetalleMembresia && !empty($venta->membresia_id)) {
            $subtotalDetalles = collect($venta->detalles)->sum(fn ($detalle) => (float) ($detalle['subtotal'] ?? 0));
            $precioMembresia = (float) data_get($venta->metadata, 'membresia.precio', max(0, (float) ($venta->subtotal ?? $venta->total ?? 0) - $subtotalDetalles));

            $venta->detalles[] = [
                'id' => null,
                'producto_id' => null,
                'membresia_id' => $venta->membresia_id,
                'tipo_detalle' => 'MEMBRESIA',
                'descripcion' => data_get($venta->metadata, 'membresia.descripcion'),
                'nombre' => $venta->venta_membresia_nombre ?: 'Membresia Revive',
                'cantidad' => 1,
                'precio_unitario' => $precioMembresia,
                'subtotal' => $precioMembresia,
            ];
        }

        return $venta;
    }

    public function getList(?int $sedeId = null, ?string $buscar = null)
    {
        $detallesSubquery = $this->buildDetalleSubquery();

        $query = DB::table('ventas.ventas as v')
            ->leftJoin('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->leftJoin('seguridad.usuarios as u', 'u.id', '=', 'v.vendedor_usuario_id')
            ->leftJoin('core.personas as pu', 'pu.id', '=', 'u.persona_id')
            ->leftJoin('socios.membresias as vm', 'vm.id', '=', 'v.membresia_id')
            ->selectRaw("
                v.*,
                COALESCE(v.persona_cedula, p.numero_identificacion) as cliente_cedula,
                COALESCE(v.vendedor_usuario_cedula, u.cedula) as vendedor_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre,
                CONCAT(COALESCE(pu.nombres, ''), ' ', COALESCE(pu.apellidos, '')) as vendedor_nombre,
                vm.nombre as venta_membresia_nombre,
                {$detallesSubquery}
            ")
            ->orderBy('v.created_at', 'desc')
            ->when($sedeId, fn ($query) => $query->where('v.sede_id', $sedeId));

        if ($buscar) {
            $term = '%' . mb_strtolower(trim($buscar)) . '%';
            $query->where(function ($builder) use ($term) {
                $builder->whereRaw('LOWER(COALESCE(v.referencia, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(v.persona_cedula, p.numero_identificacion, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(v.vendedor_usuario_cedula, u.cedula, \'\')) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(CONCAT(COALESCE(pu.nombres, ''), ' ', COALESCE(pu.apellidos, ''))) LIKE ?", [$term])
                    ->orWhereRaw('LOWER(COALESCE(v.forma_pago, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('CAST(v.id AS TEXT) LIKE ?', [$term]);
            });
        }

        return $query->get()
            ->map(fn ($venta) => $this->hydrateVenta($venta));
    }

    public function getAbiertas(?int $personaId = null, ?int $sedeId = null, ?string $fechaConsumo = null, ?int $vendedorUsuarioId = null)
    {
        $detallesSubquery = $this->buildDetalleSubquery();

        $query = DB::table('ventas.ventas as v')
            ->leftJoin('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->leftJoin('seguridad.usuarios as u', 'u.id', '=', 'v.vendedor_usuario_id')
            ->leftJoin('core.personas as pu', 'pu.id', '=', 'u.persona_id')
            ->leftJoin('socios.membresias as vm', 'vm.id', '=', 'v.membresia_id')
            ->selectRaw("
                v.*,
                p.numero_identificacion as cliente_cedula,
                CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre,
                CONCAT(COALESCE(pu.nombres, ''), ' ', COALESCE(pu.apellidos, '')) as vendedor_nombre,
                vm.nombre as venta_membresia_nombre,
                {$detallesSubquery}
            ")
            ->whereIn('v.estado_pago', ['PENDIENTE', 'ABONADO'])
            ->where('v.saldo_pendiente', '>', 0)
            ->orderByDesc('v.updated_at')
            ->orderByDesc('v.id');

        if ($personaId) {
            $query->where('v.persona_id', $personaId);
        }

        if ($sedeId) {
            $query->where('v.sede_id', $sedeId);
        }

        if ($vendedorUsuarioId) {
            $query->where('v.vendedor_usuario_id', $vendedorUsuarioId);
        }

        if ($fechaConsumo) {
            $query->whereDate('v.fecha_consumo', $fechaConsumo);
        }

        return $query->get()->map(fn ($venta) => $this->hydrateVenta($venta));
    }

    public function cierreCaja(?int $sedeId, string $fecha, ?int $vendedorUsuarioId = null, ?string $buscar = null, string $buscarTipo = 'todos'): array
    {
        $ventas = $this->getList($sedeId)
            ->filter(fn ($venta) => (string) ($venta->fecha_consumo ?? '') === $fecha)
            ->when($vendedorUsuarioId, fn ($items) => $items->filter(
                fn ($venta) => (int) ($venta->vendedor_usuario_id ?? 0) === $vendedorUsuarioId
            ))
            ->when($buscar, fn ($items) => $this->filterVentasBySearch($items, $buscar, $buscarTipo))
            ->values();

        $pagos = DB::table('ventas.venta_pagos as vp')
            ->join('ventas.ventas as v', 'v.id', '=', 'vp.venta_id')
            ->leftJoin('core.personas as p', 'p.id', '=', 'v.persona_id')
            ->whereDate('vp.created_at', $fecha)
            ->when($sedeId, fn ($query) => $query->where('v.sede_id', $sedeId))
            ->when($vendedorUsuarioId, fn ($query) => $query->where('v.vendedor_usuario_id', $vendedorUsuarioId))
            ->when($buscar, function ($query) use ($buscar) {
                $term = '%' . mb_strtolower(trim($buscar)) . '%';

                $query->where(function ($builder) use ($term) {
                    $builder->whereRaw('LOWER(v.referencia) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(p.numero_identificacion, \'\')) LIKE ?', [$term])
                        ->orWhereRaw("LOWER(CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, ''))) LIKE ?", [$term]);
                });
            })
            ->select(
                'vp.id',
                'vp.venta_id',
                'vp.forma_pago',
                'vp.monto',
                'vp.referencia_pago',
                'vp.created_at',
                'v.referencia',
                'v.fecha_consumo',
                'v.total',
                'v.estado_pago',
                'v.saldo_pendiente',
                'p.numero_identificacion as cliente_cedula',
                DB::raw("CONCAT(COALESCE(p.nombres, ''), ' ', COALESCE(p.apellidos, '')) as cliente_nombre")
            )
            ->orderBy('vp.created_at')
            ->get();

        $pendientes = $this->getAbiertas(null, $sedeId)
            ->filter(fn ($venta) => (string) ($venta->fecha_consumo ?? '') <= $fecha)
            ->when($vendedorUsuarioId, fn ($items) => $items->filter(
                fn ($venta) => (int) ($venta->vendedor_usuario_id ?? 0) === $vendedorUsuarioId
            ))
            ->when($buscar, fn ($items) => $this->filterVentasBySearch($items, $buscar, $buscarTipo))
            ->values();

        $porFormaPago = $pagos
            ->groupBy(fn ($pago) => strtoupper((string) ($pago->forma_pago ?: 'OTRO')))
            ->map(fn ($group, $forma) => [
                'forma_pago' => $forma,
                'cantidad' => $group->count(),
                'total' => round((float) $group->sum('monto'), 2),
            ])
            ->values();

        $cobrosDeudasAnteriores = $pagos
            ->filter(fn ($pago) => (string) ($pago->fecha_consumo ?? '') < $fecha)
            ->values();

        $ventasPendientesDia = $ventas
            ->filter(fn ($venta) => in_array(strtoupper((string) ($venta->estado_pago ?? '')), ['PENDIENTE', 'ABONADO'], true)
                && (float) ($venta->saldo_pendiente ?? 0) > 0)
            ->values();
        $pagosPorVenta = $pagos
            ->groupBy('venta_id')
            ->map(fn ($items) => [
                'total' => round((float) $items->sum('monto'), 2),
                'formas' => $items
                    ->pluck('forma_pago')
                    ->filter()
                    ->map(fn ($forma) => strtoupper((string) $forma))
                    ->unique()
                    ->values()
                    ->implode(', '),
            ]);
        $detalleCierre = $ventas
            ->map(function ($venta) use ($pagosPorVenta) {
                $detalleItems = collect($venta->detalles ?? [])
                    ->map(function ($item) {
                        $nombre = trim((string) ($item['nombre'] ?? $item['descripcion'] ?? 'Detalle'));
                        $cantidad = (float) ($item['cantidad'] ?? 0);
                        $precioUnitario = round((float) ($item['precio_unitario'] ?? 0), 2);
                        $subtotal = round((float) ($item['subtotal'] ?? ($cantidad * $precioUnitario)), 2);

                        return [
                            'nombre' => $nombre,
                            'tipo_detalle' => strtoupper((string) ($item['tipo_detalle'] ?? 'PRODUCTO')),
                            'cantidad' => $cantidad,
                            'precio_unitario' => $precioUnitario,
                            'subtotal' => $subtotal,
                        ];
                    })
                    ->filter(fn ($item) => $item['nombre'] !== '')
                    ->values();
                $detalle = $detalleItems
                    ->map(function ($item) {
                        $cantidad = rtrim(rtrim(number_format((float) $item['cantidad'], 2, '.', ''), '0'), '.');
                        $precio = number_format((float) $item['precio_unitario'], 2, '.', '');
                        $subtotal = number_format((float) $item['subtotal'], 2, '.', '');

                        return "{$item['nombre']} x {$cantidad} @ {$precio} = {$subtotal}";
                    })
                    ->implode(', ');
                $pagosVenta = $pagosPorVenta->get($venta->id, ['total' => 0, 'formas' => '']);
                $estado = strtoupper((string) ($venta->estado_pago ?? ''));
                $estadoLabel = match ($estado) {
                    'PAGADO' => 'Pagada',
                    'ABONADO' => 'Abonada',
                    'PENDIENTE' => 'Por pagar',
                    default => $estado ?: 'Sin estado',
                };

                return [
                    'venta_id' => (int) $venta->id,
                    'factura' => $venta->referencia ?: ('#' . str_pad((string) $venta->id, 5, '0', STR_PAD_LEFT)),
                    'fecha_consumo' => $venta->fecha_consumo,
                    'cliente' => trim((string) ($venta->cliente_nombre ?? '')) ?: 'Consumidor final',
                    'cedula' => $venta->cliente_cedula,
                    'vendedor' => trim((string) ($venta->vendedor_nombre ?? '')) ?: 'Sin vendedor',
                    'detalle' => $detalle ?: ($venta->observacion ?: 'Venta POS'),
                    'detalle_items' => $detalleItems->all(),
                    'forma_pago' => $pagosVenta['formas'] ?: ($venta->forma_pago ?: 'PENDIENTE'),
                    'estado_pago' => $estado,
                    'estado_label' => $estadoLabel,
                    'total' => round((float) ($venta->total ?? 0), 2),
                    'cobrado' => round((float) ($pagosVenta['total'] ?? 0), 2),
                    'saldo' => round((float) ($venta->saldo_pendiente ?? 0), 2),
                ];
            })
            ->values();
        $totalesCierre = [
            'ventas' => $detalleCierre->count(),
            'total' => round((float) $detalleCierre->sum('total'), 2),
            'cobrado' => round((float) $detalleCierre->sum('cobrado'), 2),
            'saldo' => round((float) $detalleCierre->sum('saldo'), 2),
        ];

        return [
            'fecha' => $fecha,
            'sede_id' => $sedeId,
            'vendedor_usuario_id' => $vendedorUsuarioId,
            'buscar' => $buscar,
            'buscar_tipo' => $buscarTipo,
            'resumen' => [
                'ventas_dia' => $ventas->count(),
                'total_consumido_dia' => round((float) $ventas->sum('total'), 2),
                'cobrado_en_caja' => round((float) $pagos->sum('monto'), 2),
                'por_cobrar_del_dia' => round((float) $ventasPendientesDia->sum('saldo_pendiente'), 2),
                'deuda_acumulada' => round((float) $pendientes->sum('saldo_pendiente'), 2),
                'cobros_deudas_anteriores' => round((float) $cobrosDeudasAnteriores->sum('monto'), 2),
            ],
            'pagos_por_forma' => $porFormaPago,
            'ventas' => $ventas,
            'pagos' => $pagos,
            'pendientes' => $pendientes,
            'cobros_deudas_anteriores' => $cobrosDeudasAnteriores,
            'detalle_cierre' => $detalleCierre,
            'totales_cierre' => $totalesCierre,
        ];
    }

    private function filterVentasBySearch($ventas, string $buscar, string $buscarTipo = 'todos')
    {
        $term = mb_strtolower(trim($buscar));

        if ($term === '') {
            return $ventas;
        }

        return $ventas->filter(function ($venta) use ($term, $buscarTipo) {
            $detalle = collect($venta->detalles ?? [])
                ->map(fn ($item) => $item['nombre'] ?? $item['descripcion'] ?? null)
                ->filter()
                ->implode(' ');
            $fields = match ($buscarTipo) {
                'factura' => [$venta->referencia ?? null, $venta->id ?? null],
                'cliente' => [$venta->cliente_cedula ?? null, $venta->cliente_nombre ?? null],
                'detalle' => [$detalle, $venta->observacion ?? null],
                default => [
                    $venta->referencia ?? null,
                    $venta->id ?? null,
                    $venta->cliente_cedula ?? null,
                    $venta->cliente_nombre ?? null,
                    $detalle,
                    $venta->observacion ?? null,
                ],
            };
            $haystack = mb_strtolower(implode(' ', array_filter($fields)));

            return str_contains($haystack, $term);
        });
    }
}
