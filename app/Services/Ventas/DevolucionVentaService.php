<?php

namespace App\Services\Ventas;

use App\Queries\Ventas\DevolucionVentaQuery;
use App\Services\Audit\AuditService;
use App\Services\Inventarios\ProductoMovimientoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevolucionVentaService
{
    public function __construct(
        private DevolucionVentaQuery $devolucionVentaQuery,
        private ProductoMovimientoService $productoMovimientoService,
        private AuditService $auditService
    ) {
    }

    public function buscarVentas(array $filters): array
    {
        return $this->devolucionVentaQuery
            ->searchVentas($filters['termino'] ?? null, $filters['sede_id'] ?? null)
            ->values()
            ->all();
    }

    public function historial(array $filters): array
    {
        return $this->devolucionVentaQuery
            ->history($filters['sede_id'] ?? null, $filters['termino'] ?? null)
            ->values()
            ->all();
    }

    public function registrar(array $input, Request $request): array
    {
        $payload = $this->normalizePayload($input);

        return DB::transaction(function () use ($payload, $request) {
            $venta = $this->devolucionVentaQuery->findVenta($payload['venta_id']);

            if (!$venta) {
                throw new RuntimeException('No se encontro la venta origen');
            }

            if (strtoupper((string) ($venta->estado_devolucion ?? '')) === 'ANULADA') {
                throw new RuntimeException('La venta ya fue anulada');
            }

            $detallesVenta = collect($venta->detalles)->keyBy('id');
            $detallesSolicitados = $payload['tipo'] === 'ANULACION'
                ? $this->buildAnulacionDetalles($detallesVenta)
                : $payload['detalles'];

            if (!$detallesSolicitados) {
                throw new RuntimeException('Debes seleccionar al menos un detalle para devolver');
            }

            $devolucionDetalles = [];
            $montoTotal = 0;
            $movimientos = [];
            $membresiasRevertidas = [];

            $devolucionId = (int) DB::table('ventas.devoluciones')->insertGetId([
                'venta_id' => $payload['venta_id'],
                'tipo' => $payload['tipo'],
                'motivo' => $payload['motivo'],
                'observacion' => $payload['observacion'],
                'reintegra_stock' => $payload['reintegra_stock'],
                'monto_total' => 0,
                'estado' => 'APLICADA',
                'metadata' => json_encode([
                    'venta_referencia' => $venta->referencia,
                    'cliente' => trim((string) ($venta->cliente_nombre ?? '')),
                ]),
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($detallesSolicitados as $detalleInput) {
                $detalleId = (int) ($detalleInput['venta_detalle_id'] ?? 0);
                $detalleVenta = $detallesVenta->get($detalleId);

                if (!$detalleVenta) {
                    throw new RuntimeException("El detalle {$detalleId} no pertenece a la venta seleccionada");
                }

                $cantidad = round((float) ($detalleInput['cantidad'] ?? 0), 2);
                $disponible = round((float) ($detalleVenta->cantidad_disponible ?? 0), 2);

                if ($cantidad <= 0) {
                    throw new RuntimeException("La cantidad para {$detalleVenta->nombre} debe ser mayor a cero");
                }

                if ($cantidad > $disponible) {
                    throw new RuntimeException("La cantidad de {$detalleVenta->nombre} supera el saldo disponible para devolver");
                }

                $precioUnitario = round((float) ($detalleVenta->precio_unitario ?? 0), 2);
                $subtotal = round($cantidad * $precioUnitario, 2);
                $tipoDetalle = strtoupper((string) ($detalleVenta->tipo_detalle ?? 'PRODUCTO'));
                $reintegraDetalle = $payload['reintegra_stock']
                    && $tipoDetalle === 'PRODUCTO'
                    && !empty($detalleVenta->producto_id)
                    && !empty($detalleVenta->controla_stock);
                $movimientoId = null;

                if ($reintegraDetalle) {
                    $movimientosDetalle = $this->registrarEntradasStock($venta, $detalleVenta, $cantidad, $precioUnitario, $devolucionId, $request);
                    $movimientoId = $movimientosDetalle[0]['id'] ?? null;
                    $movimientos = array_merge($movimientos, $movimientosDetalle);
                }

                if ($tipoDetalle === 'MEMBRESIA') {
                    $membresiaRevertida = $this->revertirAsignacionMembresia($venta, $detalleVenta, $request);
                    if ($membresiaRevertida) {
                        $membresiasRevertidas[] = $membresiaRevertida;
                    }
                }

                $devolucionDetalles[] = [
                    'devolucion_id' => $devolucionId,
                    'venta_detalle_id' => $detalleId,
                    'producto_id' => $detalleVenta->producto_id,
                    'membresia_id' => $detalleVenta->membresia_id,
                    'tipo_detalle' => $tipoDetalle,
                    'descripcion' => $detalleVenta->nombre,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                    'reintegra_stock' => $reintegraDetalle,
                    'movimiento_inventario_id' => $movimientoId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $montoTotal += $subtotal;
            }

            DB::table('ventas.devolucion_detalles')->insert($devolucionDetalles);

            DB::table('ventas.devoluciones')
                ->where('id', $devolucionId)
                ->update([
                    'monto_total' => round($montoTotal, 2),
                    'metadata' => json_encode([
                        'venta_referencia' => $venta->referencia,
                        'cliente' => trim((string) ($venta->cliente_nombre ?? '')),
                        'movimientos_inventario' => array_map(fn ($mov) => $mov['id'] ?? null, $movimientos),
                        'membresias_revertidas' => $membresiasRevertidas,
                    ]),
                    'updated_at' => now(),
                ]);

            $estadoDevolucion = $this->resolveEstadoVenta($payload['venta_id'], (float) $venta->total);
            $montoDevuelto = $this->sumMontoDevuelto($payload['venta_id']);
            $ventaAntes = (array) $venta;

            DB::table('ventas.ventas')
                ->where('id', $payload['venta_id'])
                ->update([
                    'estado_devolucion' => $estadoDevolucion,
                    'monto_devuelto' => $montoDevuelto,
                    'anulada_at' => $estadoDevolucion === 'ANULADA' ? now() : null,
                    'anulada_by' => $estadoDevolucion === 'ANULADA' ? $request->user()?->id : null,
                    'updated_by' => $request->user()?->id,
                    'updated_at' => now(),
                ]);

            $devolucion = DB::table('ventas.devoluciones')->where('id', $devolucionId)->first();
            $ventaDespues = DB::table('ventas.ventas')->where('id', $payload['venta_id'])->first();

            $this->auditService->created($request, 'ventas_devoluciones', $devolucionId, [
                'venta_id' => $payload['venta_id'],
                'tipo' => $payload['tipo'],
                'motivo' => $payload['motivo'],
                'monto_total' => round($montoTotal, 2),
                'reintegra_stock' => $payload['reintegra_stock'],
                'detalles' => $devolucionDetalles,
                'membresias_revertidas' => $membresiasRevertidas,
            ], [
                'modulo' => 'ventas',
                'accion' => strtolower($payload['tipo']) === 'anulacion' ? 'anular_venta' : 'registrar_devolucion',
                'esquema' => 'ventas',
                'sede_id' => $venta->sede_id,
            ]);

            $this->auditService->updated($request, 'ventas', $payload['venta_id'], $ventaAntes, $ventaDespues, [
                'modulo' => 'ventas',
                'accion' => 'actualizar_estado_devolucion',
                'esquema' => 'ventas',
                'sede_id' => $venta->sede_id,
            ]);

            return [
                'devolucion' => $devolucion,
                'detalles' => $devolucionDetalles,
                'venta' => $this->devolucionVentaQuery->findVenta($payload['venta_id']),
            ];
        });
    }

    private function normalizePayload(array $input): array
    {
        $tipo = strtoupper(trim((string) ($input['tipo'] ?? 'DEVOLUCION')));

        return [
            'venta_id' => (int) ($input['venta_id'] ?? 0),
            'tipo' => in_array($tipo, ['DEVOLUCION', 'ANULACION'], true) ? $tipo : 'DEVOLUCION',
            'motivo' => trim((string) ($input['motivo'] ?? '')),
            'observacion' => trim((string) ($input['observacion'] ?? '')),
            'reintegra_stock' => (bool) ($input['reintegra_stock'] ?? true),
            'detalles' => array_values(array_filter($input['detalles'] ?? [], fn ($row) => is_array($row))),
        ];
    }

    private function buildAnulacionDetalles($detallesVenta): array
    {
        return $detallesVenta
            ->filter(fn ($detalle) => (float) ($detalle->cantidad_disponible ?? 0) > 0)
            ->map(fn ($detalle) => [
                'venta_detalle_id' => (int) $detalle->id,
                'cantidad' => (float) $detalle->cantidad_disponible,
            ])
            ->values()
            ->all();
    }

    private function registrarEntradasStock(object $venta, object $detalle, float $cantidad, float $precioUnitario, int $devolucionId, Request $request): array
    {
        $basePayload = [
            'producto_id' => (int) $detalle->producto_id,
            'sede_id' => (int) $venta->sede_id,
            'motivo' => 'DEVOLUCION_VENTA',
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'referencia_tipo' => 'DEVOLUCION_VENTA',
            'referencia_id' => $devolucionId,
            'observacion' => trim(sprintf(
                'Devolucion venta #%s | Ref: %s | Detalle: %s',
                $venta->id,
                $venta->referencia,
                $detalle->nombre
            )),
        ];

        if (empty($detalle->maneja_lotes)) {
            return [$this->productoMovimientoService->registrarEntrada($basePayload, $request)];
        }

        $remaining = $cantidad;
        $movimientos = [];

        foreach ($this->findOriginalLotSegments($venta, $detalle) as $segment) {
            if ($remaining <= 0) {
                break;
            }

            $cantidadSegmento = min($remaining, (float) $segment->cantidad);
            $movimientos[] = $this->productoMovimientoService->registrarEntrada(array_merge($basePayload, [
                'lote_id' => (int) $segment->lote_id,
                'cantidad' => $cantidadSegmento,
            ]), $request);
            $remaining = round($remaining - $cantidadSegmento, 2);
        }

        if ($remaining > 0) {
            $movimientos[] = $this->productoMovimientoService->registrarEntrada(array_merge($basePayload, [
                'codigo_lote' => 'DEV-' . $venta->id . '-' . $detalle->id,
                'cantidad' => $remaining,
            ]), $request);
        }

        return $movimientos;
    }

    private function revertirAsignacionMembresia(object $venta, object $detalle, Request $request): ?array
    {
        $metadata = $this->decodeMetadata($venta->metadata ?? null);
        $asignacionId = (int) ($metadata['asignacion_id'] ?? 0);

        if (!$asignacionId || empty($detalle->membresia_id)) {
            return null;
        }

        $asignacion = DB::table('socios.socio_membresias')->where('id', $asignacionId)->first();

        if (!$asignacion) {
            return null;
        }

        $estadoInactivoId = DB::table('core.estados')->where('codigo', 'INACTIVO')->value('id');
        $before = (array) $asignacion;

        DB::table('socios.socio_membresias')
            ->where('id', $asignacionId)
            ->update([
                'estado_id' => $estadoInactivoId ?: $asignacion->estado_id,
                'updated_at' => now(),
            ]);

        $after = DB::table('socios.socio_membresias')->where('id', $asignacionId)->first();

        $this->auditService->updated($request, 'socio_membresias', $asignacionId, $before, $after, [
            'modulo' => 'ventas',
            'accion' => 'revertir_membresia_por_devolucion',
            'esquema' => 'socios',
            'sede_id' => $venta->sede_id,
        ]);

        return [
            'asignacion_id' => $asignacionId,
            'membresia_id' => (int) $detalle->membresia_id,
            'estado_anterior_id' => $before['estado_id'] ?? null,
            'estado_nuevo_id' => $after->estado_id ?? null,
        ];
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            return json_decode($metadata, true) ?: [];
        }

        return [];
    }

    private function findOriginalLotSegments(object $venta, object $detalle)
    {
        $referencia = (string) ($venta->referencia ?? '');
        $referenciaConsumo = str_ends_with($referencia, '-CON') ? $referencia : $referencia . '-CON';

        return DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $detalle->producto_id)
            ->where('sede_id', $venta->sede_id)
            ->where('tipo_movimiento', 'SALIDA')
            ->where('referencia_tipo', 'VENTA_POS')
            ->where('observacion', 'like', '%' . $referenciaConsumo . '%')
            ->whereNotNull('lote_id')
            ->orderBy('id')
            ->get(['lote_id', 'cantidad']);
    }

    private function sumMontoDevuelto(int $ventaId): float
    {
        return round((float) DB::table('ventas.devoluciones')
            ->where('venta_id', $ventaId)
            ->where('estado', 'APLICADA')
            ->sum('monto_total'), 2);
    }

    private function resolveEstadoVenta(int $ventaId, float $ventaTotal): string
    {
        $montoDevuelto = $this->sumMontoDevuelto($ventaId);

        if ($ventaTotal > 0 && $montoDevuelto >= round($ventaTotal, 2)) {
            return 'ANULADA';
        }

        return $montoDevuelto > 0 ? 'PARCIAL' : 'SIN_DEVOLUCION';
    }
}
