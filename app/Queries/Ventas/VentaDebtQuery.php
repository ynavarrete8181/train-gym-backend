<?php

namespace App\Queries\Ventas;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VentaDebtQuery
{
    public function resumenPorPersona(int $personaId): array
    {
        if (
            !Schema::hasColumn('ventas.ventas', 'tipo_venta')
            || !Schema::hasColumn('ventas.ventas', 'estado_pago')
            || !Schema::hasColumn('ventas.ventas', 'saldo_pendiente')
            || !Schema::hasColumn('ventas.ventas', 'fecha_consumo')
        ) {
            return [
                'tiene_deuda' => false,
                'cantidad' => 0,
                'saldo_total' => 0,
                'saldo_consumo' => 0,
                'saldo_membresia' => 0,
                'items' => [],
            ];
        }

        try {
            $ventas = DB::table('ventas.ventas as v')
                ->leftJoin('socios.membresias as m', 'm.id', '=', 'v.membresia_id')
                ->where('v.persona_id', $personaId)
                ->whereIn('v.estado_pago', ['PENDIENTE', 'ABONADO'])
                ->where('v.saldo_pendiente', '>', 0)
                ->orderByDesc('v.fecha_consumo')
                ->orderByDesc('v.id')
                ->select(
                    'v.id',
                    'v.referencia',
                    'v.tipo_venta',
                    'v.estado_pago',
                    'v.forma_pago',
                    'v.total',
                    'v.saldo_pendiente',
                    'v.fecha_consumo',
                    'v.observacion',
                    'm.nombre as membresia_nombre'
                )
                ->get();
        } catch (QueryException) {
            return [
                'tiene_deuda' => false,
                'cantidad' => 0,
                'saldo_total' => 0,
                'saldo_consumo' => 0,
                'saldo_membresia' => 0,
                'items' => [],
            ];
        }

        $items = $ventas->map(function ($venta) {
            $descripcion = $venta->tipo_venta === 'MEMBRESIA'
                ? ($venta->membresia_nombre ?: 'Membresia pendiente')
                : ($venta->observacion ?: 'Consumo pendiente');

            return [
                'venta_id' => (int) $venta->id,
                'referencia' => $venta->referencia,
                'tipo_venta' => $venta->tipo_venta,
                'estado_pago' => $venta->estado_pago,
                'forma_pago' => $venta->forma_pago,
                'descripcion' => $descripcion,
                'fecha_consumo' => $venta->fecha_consumo,
                'total' => (float) $venta->total,
                'saldo_pendiente' => (float) $venta->saldo_pendiente,
            ];
        })->values();

        $porTipo = $items->groupBy('tipo_venta')->map(fn ($group) => [
            'cantidad' => $group->count(),
            'saldo' => round((float) $group->sum('saldo_pendiente'), 2),
        ]);

        return [
            'tiene_deuda' => $items->isNotEmpty(),
            'cantidad' => $items->count(),
            'saldo_total' => round((float) $items->sum('saldo_pendiente'), 2),
            'saldo_consumo' => (float) ($porTipo->get('CONSUMO')['saldo'] ?? 0),
            'saldo_membresia' => (float) ($porTipo->get('MEMBRESIA')['saldo'] ?? 0),
            'items' => $items->take(10)->all(),
        ];
    }
}
