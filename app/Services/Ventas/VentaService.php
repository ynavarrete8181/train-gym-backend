<?php

namespace App\Services\Ventas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VentaService
{
    private ?array $ventaDetalleColumns = null;

    private function businessDate(): string
    {
        return now(config('app.timezone', 'America/Guayaquil'))->toDateString();
    }

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

    public function store(array $payload, int $userId)
    {
        return DB::transaction(function () use ($payload, $userId) {
            $total = (float) ($payload['total'] ?? 0);
            $estadoPago = strtoupper((string) ($payload['estado_pago'] ?? 'PAGADO'));
            $saldoPendiente = $estadoPago === 'PENDIENTE'
                ? round((float) ($payload['saldo_pendiente'] ?? $total), 2)
                : round((float) ($payload['saldo_pendiente'] ?? 0), 2);
            $formaPago = $estadoPago === 'PENDIENTE'
                ? 'PENDIENTE'
                : ($payload['forma_pago'] ?? 'EFECTIVO');

            $ventaId = DB::table('ventas.ventas')->insertGetId([
                'sede_id' => $payload['sede_id'],
                'cliente_id' => $payload['cliente_id'] ?? null,
                'persona_id' => $payload['persona_id'] ?? ($payload['cliente_id'] ?? null),
                'vendedor_id' => $userId,
                'vendedor_usuario_id' => $userId,
                'referencia' => $payload['referencia'] ?? ('POS-' . now()->format('YmdHis')),
                'forma_pago' => $formaPago,
                'observacion' => $payload['observacion'] ?? 'Venta POS',
                'subtotal' => $payload['subtotal'] ?? $total,
                'iva' => $payload['iva'] ?? 0,
                'total' => $total,
                'tipo_venta' => $payload['tipo_venta'] ?? 'CONSUMO',
                'estado_pago' => $estadoPago,
                'saldo_pendiente' => $saldoPendiente,
                'fecha_consumo' => $payload['fecha_consumo'] ?? $this->businessDate(),
                'membresia_id' => $payload['membresia_id'] ?? null,
                'metadata' => isset($payload['metadata']) ? json_encode($payload['metadata']) : json_encode(new \stdClass()),
                'estado' => 1,
                'fecha' => now(),
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (isset($payload['detalles']) && is_array($payload['detalles'])) {
                foreach ($payload['detalles'] as $detalle) {
                    $detallePayload = [
                        'venta_id' => $ventaId,
                        'producto_id' => $detalle['producto_id'] ?? null,
                        'cantidad' => $detalle['cantidad'],
                        'precio_unitario' => $detalle['precio_unitario'],
                        'subtotal' => round((float) ($detalle['subtotal'] ?? ($detalle['cantidad'] * $detalle['precio_unitario'])), 2),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($this->ventaDetalleHasColumn('membresia_id')) {
                        $detallePayload['membresia_id'] = $detalle['membresia_id'] ?? null;
                    }

                    if ($this->ventaDetalleHasColumn('tipo_detalle')) {
                        $detallePayload['tipo_detalle'] = strtoupper((string) ($detalle['tipo_detalle'] ?? (!empty($detalle['membresia_id']) ? 'MEMBRESIA' : 'PRODUCTO')));
                    }

                    if ($this->ventaDetalleHasColumn('descripcion')) {
                        $detallePayload['descripcion'] = $detalle['descripcion'] ?? null;
                    }

                    DB::table('ventas.venta_detalles')->insert($detallePayload);
                }
            }

            if ($estadoPago !== 'PENDIENTE' && !empty($payload['forma_pago'])) {
                DB::table('ventas.venta_pagos')->insert([
                    'venta_id' => $ventaId,
                    'forma_pago' => $payload['forma_pago'],
                    'monto' => $total,
                    'referencia_pago' => $payload['referencia'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $ventaId;
        });
    }

    public function updateOpenSale(int $ventaId, array $payload, int $userId)
    {
        return DB::transaction(function () use ($ventaId, $payload, $userId) {
            $venta = DB::table('ventas.ventas')->where('id', $ventaId)->first();

            if (!$venta) {
                throw new \RuntimeException('No se encontro la venta pendiente seleccionada');
            }

            if (!in_array(strtoupper((string) ($venta->estado_pago ?? '')), ['PENDIENTE', 'ABONADO'], true)) {
                throw new \RuntimeException('La venta seleccionada ya no se puede actualizar desde punto de venta');
            }

            $total = (float) ($payload['total'] ?? 0);
            $estadoPago = strtoupper((string) ($payload['estado_pago'] ?? 'PAGADO'));
            $saldoPendiente = $estadoPago === 'PENDIENTE'
                ? round((float) ($payload['saldo_pendiente'] ?? $total), 2)
                : round((float) ($payload['saldo_pendiente'] ?? 0), 2);
            $formaPago = $estadoPago === 'PENDIENTE'
                ? 'PENDIENTE'
                : ($payload['forma_pago'] ?? 'EFECTIVO');

            DB::table('ventas.ventas')
                ->where('id', $ventaId)
                ->update([
                    'sede_id' => $payload['sede_id'],
                    'cliente_id' => $payload['cliente_id'] ?? null,
                    'persona_id' => $payload['persona_id'] ?? ($payload['cliente_id'] ?? null),
                    'referencia' => $payload['referencia'] ?? $venta->referencia,
                    'forma_pago' => $formaPago,
                    'observacion' => $payload['observacion'] ?? 'Venta POS',
                    'subtotal' => $payload['subtotal'] ?? $total,
                    'iva' => $payload['iva'] ?? 0,
                    'total' => $total,
                    'tipo_venta' => $payload['tipo_venta'] ?? 'CONSUMO',
                    'estado_pago' => $estadoPago,
                    'saldo_pendiente' => $saldoPendiente,
                    'fecha_consumo' => $payload['fecha_consumo'] ?? $venta->fecha_consumo ?? $this->businessDate(),
                    'membresia_id' => $payload['membresia_id'] ?? null,
                    'metadata' => isset($payload['metadata']) ? json_encode($payload['metadata']) : json_encode(new \stdClass()),
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            DB::table('ventas.venta_detalles')->where('venta_id', $ventaId)->delete();

            if (isset($payload['detalles']) && is_array($payload['detalles'])) {
                foreach ($payload['detalles'] as $detalle) {
                    $detallePayload = [
                        'venta_id' => $ventaId,
                        'producto_id' => $detalle['producto_id'] ?? null,
                        'cantidad' => $detalle['cantidad'],
                        'precio_unitario' => $detalle['precio_unitario'],
                        'subtotal' => round((float) ($detalle['subtotal'] ?? ($detalle['cantidad'] * $detalle['precio_unitario'])), 2),
                        'created_at' => $venta->created_at ?? now(),
                        'updated_at' => now(),
                    ];

                    if ($this->ventaDetalleHasColumn('membresia_id')) {
                        $detallePayload['membresia_id'] = $detalle['membresia_id'] ?? null;
                    }

                    if ($this->ventaDetalleHasColumn('tipo_detalle')) {
                        $detallePayload['tipo_detalle'] = strtoupper((string) ($detalle['tipo_detalle'] ?? (!empty($detalle['membresia_id']) ? 'MEMBRESIA' : 'PRODUCTO')));
                    }

                    if ($this->ventaDetalleHasColumn('descripcion')) {
                        $detallePayload['descripcion'] = $detalle['descripcion'] ?? null;
                    }

                    DB::table('ventas.venta_detalles')->insert($detallePayload);
                }
            }

            DB::table('ventas.venta_pagos')->where('venta_id', $ventaId)->delete();

            if ($estadoPago !== 'PENDIENTE' && !empty($payload['forma_pago'])) {
                DB::table('ventas.venta_pagos')->insert([
                    'venta_id' => $ventaId,
                    'forma_pago' => $payload['forma_pago'],
                    'monto' => $total,
                    'referencia_pago' => $payload['referencia'] ?? $venta->referencia,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $ventaId;
        });
    }
}
