<?php

namespace App\Services\Ventas;

use App\Queries\Inventarios\ProductoQuery;
use App\Queries\Ventas\PuntoVentaQuery;
use App\Services\Inventarios\ProductoMovimientoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PuntoVentaService
{
    public function __construct(
        private PuntoVentaQuery $puntoVentaQuery,
        private ProductoQuery $productoQuery,
        private ProductoMovimientoService $productoMovimientoService
    ) {
    }

    public function procesar(array $input, Request $request): array
    {
        $payload = $this->normalizePayload($input);

        if (!$this->puntoVentaQuery->sedeExists($payload['sede_id'])) {
            throw new RuntimeException('La sede seleccionada no esta disponible para punto de venta');
        }

        return DB::transaction(function () use ($payload, $request) {
            $itemsProcesados = [];
            $movimientosRegistrados = 0;
            $total = 0;

            foreach ($payload['items'] as $item) {
                $producto = $this->productoQuery->find($item['producto_id']);

                if (!$producto) {
                    throw new RuntimeException("No se encontro el producto con id {$item['producto_id']}");
                }

                if ((int) ($producto['estado'] ?? 0) !== 1) {
                    throw new RuntimeException("El producto {$producto['nombre']} esta inactivo");
                }

                if (empty($producto['controla_stock'])) {
                    throw new RuntimeException("El producto {$producto['nombre']} no maneja control de stock");
                }

                if (empty($producto['permite_decimales']) && floor($item['cantidad']) !== $item['cantidad']) {
                    throw new RuntimeException("El producto {$producto['nombre']} no permite cantidades decimales");
                }

                $precioUnitario = $item['precio_unitario'];
                if ($precioUnitario === null) {
                    $precioUnitario = isset($producto['precio_venta']) ? (float) $producto['precio_venta'] : 0;
                }

                $costoUnitario = $item['costo_unitario'];
                if ($costoUnitario === null) {
                    $costoUnitario = isset($producto['precio_costo']) ? (float) $producto['precio_costo'] : null;
                }

                $movimientosItem = [];

                if (!empty($producto['maneja_lotes'])) {
                    $lotPlan = $this->buildLotPlan(
                        $producto['id'],
                        $payload['sede_id'],
                        $item['cantidad'],
                        !empty($producto['maneja_vencimiento'])
                    );

                    foreach ($lotPlan as $segment) {
                        $movimientosItem[] = $this->productoMovimientoService->registrarSalida([
                            'producto_id' => $producto['id'],
                            'sede_id' => $payload['sede_id'],
                            'lote_id' => $segment['lote_id'],
                            'motivo' => 'VENTA',
                            'cantidad' => $segment['cantidad'],
                            'precio_unitario' => $precioUnitario,
                            'costo_unitario' => $costoUnitario,
                            'referencia_tipo' => 'VENTA_POS',
                            'observacion' => $this->buildObservacion($payload),
                        ], $request);
                    }
                } else {
                    $movimientosItem[] = $this->productoMovimientoService->registrarSalida([
                        'producto_id' => $producto['id'],
                        'sede_id' => $payload['sede_id'],
                        'motivo' => 'VENTA',
                        'cantidad' => $item['cantidad'],
                        'precio_unitario' => $precioUnitario,
                        'costo_unitario' => $costoUnitario,
                        'referencia_tipo' => 'VENTA_POS',
                        'observacion' => $this->buildObservacion($payload),
                    ], $request);
                }

                $subtotal = round($item['cantidad'] * $precioUnitario, 2);
                $total += $subtotal;
                $movimientosRegistrados += count($movimientosItem);

                $itemsProcesados[] = [
                    'producto_id' => $producto['id'],
                    'codigo' => $producto['codigo'],
                    'nombre' => $producto['nombre'],
                    'tipo_precio' => $item['tipo_precio'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                    'movimientos' => $movimientosItem,
                ];
            }

            return [
                'referencia' => $payload['referencia'],
                'sede_id' => $payload['sede_id'],
                'forma_pago' => $payload['forma_pago'],
                'observacion' => $payload['observacion'],
                'items' => $itemsProcesados,
                'items_procesados' => count($itemsProcesados),
                'movimientos_registrados' => $movimientosRegistrados,
                'total' => round($total, 2),
            ];
        });
    }

    private function normalizePayload(array $input): array
    {
        $referencia = trim((string) ($input['referencia'] ?? ''));

        return [
            'sede_id' => (int) ($input['sede_id'] ?? 0),
            'forma_pago' => strtoupper(trim((string) ($input['forma_pago'] ?? 'EFECTIVO'))),
            'referencia' => $referencia !== '' ? $referencia : $this->generateReference(),
            'observacion' => trim((string) ($input['observacion'] ?? 'Venta POS')),
            'items' => array_map(fn ($row) => [
                'producto_id' => (int) ($row['producto_id'] ?? 0),
                'cantidad' => (float) ($row['cantidad'] ?? 0),
                'precio_unitario' => array_key_exists('precio_unitario', $row) && $row['precio_unitario'] !== null
                    ? (float) $row['precio_unitario']
                    : null,
                'costo_unitario' => array_key_exists('costo_unitario', $row) && $row['costo_unitario'] !== null
                    ? (float) $row['costo_unitario']
                    : null,
                'tipo_precio' => !empty($row['tipo_precio'])
                    ? strtoupper(trim((string) $row['tipo_precio']))
                    : null,
            ], array_values(array_filter($input['items'] ?? [], fn ($row) => is_array($row)))),
        ];
    }

    private function buildLotPlan(int $productoId, int $sedeId, float $cantidad, bool $excludeExpired): array
    {
        $remaining = $cantidad;
        $segments = [];
        $lots = $this->puntoVentaQuery->availableLotsForSale($productoId, $sedeId, $excludeExpired);

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) $lot['stock_actual'];
            if ($available <= 0) {
                continue;
            }

            $take = min($remaining, $available);

            $segments[] = [
                'lote_id' => $lot['id'],
                'codigo_lote' => $lot['codigo_lote'],
                'cantidad' => $take,
            ];

            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new RuntimeException('Stock insuficiente en lotes disponibles para completar la venta');
        }

        return $segments;
    }

    private function buildObservacion(array $payload): string
    {
        $base = $payload['observacion'] !== '' ? $payload['observacion'] : 'Venta POS';

        return trim(sprintf(
            '%s | Ref: %s | Pago: %s',
            $base,
            $payload['referencia'],
            $payload['forma_pago']
        ));
    }

    private function generateReference(): string
    {
        return 'POS-' . now()->format('Ymd-His-u');
    }
}
