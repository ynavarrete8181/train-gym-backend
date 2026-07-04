<?php

namespace App\Services\Ventas;

use App\Queries\Inventarios\ProductoQuery;
use App\Queries\Ventas\PuntoVentaQuery;
use App\Queries\Ventas\VentaDebtQuery;
use App\Services\Inventarios\ProductoMovimientoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PuntoVentaService
{
    public function __construct(
        private PuntoVentaQuery $puntoVentaQuery,
        private VentaDebtQuery $ventaDebtQuery,
        private ProductoQuery $productoQuery,
        private ProductoMovimientoService $productoMovimientoService,
        private VentaService $ventaService
    ) {
    }

    private function businessToday(): Carbon
    {
        return Carbon::today(config('app.timezone', 'America/Guayaquil'));
    }

    public function catalogo(int $sedeId): array
    {
        if (!$this->puntoVentaQuery->sedeExists($sedeId)) {
            throw new RuntimeException('La sede seleccionada no esta disponible para punto de venta');
        }

        return $this->puntoVentaQuery->posCatalog($sedeId);
    }

    public function procesar(array $input, Request $request): array
    {
        $payload = $this->normalizePayload($input);

        if (!$this->puntoVentaQuery->sedeExists($payload['sede_id'])) {
            throw new RuntimeException('La sede seleccionada no esta disponible para punto de venta');
        }

        return DB::transaction(function () use ($payload, $request) {
            $lineas = [];
            $itemsProcesados = [];
            $movimientosRegistrados = 0;
            $totalGeneral = 0;
            $membresiaInfo = null;
            $asignacionInfo = null;
            $tipoVentaPartes = [];
            $metadata = [
                'origen' => 'POS',
            ];

            if ($payload['membresia_id']) {
                $resultadoMembresia = $payload['venta_id']
                    ? $this->prepararMembresiaExistente($payload)
                    : $this->prepararMembresia($payload, $request);
                $lineas[] = $resultadoMembresia['detalle'];
                $totalGeneral += $resultadoMembresia['total'];
                $membresiaInfo = $resultadoMembresia['membresia'];
                $asignacionInfo = $resultadoMembresia['asignacion'] ?? null;
                $tipoVentaPartes[] = 'MEMBRESIA';

                $metadata['membresia'] = $resultadoMembresia['membresia'];
                if (!empty($resultadoMembresia['asignacion']['id'])) {
                    $metadata['asignacion_id'] = $resultadoMembresia['asignacion']['id'];
                    $metadata['socio_id'] = $resultadoMembresia['asignacion']['socio_id'];
                    $metadata['fecha_inicio'] = $resultadoMembresia['asignacion']['fecha_inicio'];
                    $metadata['fecha_fin'] = $resultadoMembresia['asignacion']['fecha_fin'];
                }
            }

            if (!empty($payload['items'])) {
                $resultadoConsumo = $this->prepararConsumo($payload, $request);
                $lineas = array_merge($lineas, $resultadoConsumo['detalles']);
                $itemsProcesados = array_merge($itemsProcesados, $resultadoConsumo['items']);
                $movimientosRegistrados += $resultadoConsumo['movimientos_registrados'];
                $totalGeneral += $resultadoConsumo['total'];
                $tipoVentaPartes[] = 'CONSUMO';

                $metadata['items'] = $resultadoConsumo['items'];
            }

            if (!$lineas) {
                throw new RuntimeException('Debes seleccionar al menos una membresia o un consumo para registrar la operacion');
            }

            $tipoVentaPartes = array_values(array_unique($tipoVentaPartes));
            $tipoVenta = count($tipoVentaPartes) > 1 ? 'COMPUESTA' : $tipoVentaPartes[0];

            $referencia = $payload['referencia'];
            $observacion = $payload['observacion'];
            if ($membresiaInfo && empty($payload['items'])) {
                $observacion = trim(sprintf(
                    '%s | Membresia %s activada para %s',
                    $payload['observacion'],
                    $membresiaInfo['nombre'],
                    $membresiaInfo['cliente_nombre']
                ));
            } elseif ($membresiaInfo && !empty($payload['items'])) {
                $observacion = trim(sprintf(
                    '%s | Membresia %s y consumo POS',
                    $payload['observacion'],
                    $membresiaInfo['nombre']
                ));
            }

            $ventaPayload = [
                'sede_id' => $payload['sede_id'],
                'cliente_id' => $payload['persona_id'],
                'persona_id' => $payload['persona_id'],
                'forma_pago' => $payload['forma_pago'],
                'estado_pago' => $payload['estado_pago'],
                'referencia' => $referencia,
                'observacion' => $observacion,
                'subtotal' => $totalGeneral,
                'iva' => 0,
                'total' => $totalGeneral,
                'tipo_venta' => $tipoVenta,
                'saldo_pendiente' => $payload['estado_pago'] === 'PENDIENTE' ? $totalGeneral : 0,
                'membresia_id' => $membresiaInfo['id'] ?? null,
                'metadata' => array_merge($metadata, [
                    'tipo' => $tipoVenta,
                    'detalle_tipos' => $tipoVentaPartes,
                ]),
                'detalles' => $lineas,
            ];

            $ventaId = $payload['venta_id']
                ? $this->ventaService->updateOpenSale($payload['venta_id'], $ventaPayload, $request->user()?->id ?? 1)
                : $this->ventaService->store($ventaPayload, $request->user()?->id ?? 1);

            $itemsRespuesta = $itemsProcesados;
            if ($membresiaInfo) {
                array_unshift($itemsRespuesta, [
                    'tipo_detalle' => 'MEMBRESIA',
                    'membresia_id' => $membresiaInfo['id'],
                    'nombre' => $membresiaInfo['nombre'],
                    'cantidad' => 1,
                    'precio_unitario' => $membresiaInfo['precio'],
                    'subtotal' => $membresiaInfo['precio'],
                    'fecha_inicio' => $asignacionInfo['fecha_inicio'] ?? null,
                    'fecha_fin' => $asignacionInfo['fecha_fin'] ?? null,
                ]);
            }

            return [
                'ventas' => [[
                    'venta_id' => $ventaId,
                    'tipo_venta' => $tipoVenta,
                    'referencia' => $referencia,
                    'sede_id' => $payload['sede_id'],
                    'forma_pago' => $payload['estado_pago'] === 'PENDIENTE' ? 'PENDIENTE' : $payload['forma_pago'],
                    'estado_pago' => $payload['estado_pago'],
                    'observacion' => $observacion,
                    'items' => $itemsRespuesta,
                    'detalle' => $lineas,
                    'items_procesados' => count($itemsRespuesta),
                    'movimientos_registrados' => $movimientosRegistrados,
                    'total' => round($totalGeneral, 2),
                    'saldo_pendiente' => $payload['estado_pago'] === 'PENDIENTE' ? round($totalGeneral, 2) : 0,
                    'asignacion' => $asignacionInfo,
                    'membresia' => $membresiaInfo,
                ]],
                'total_general' => round($totalGeneral, 2),
                'estado_pago' => $payload['estado_pago'],
                'deuda_actualizada' => $payload['persona_id']
                    ? $this->ventaDebtQuery->resumenPorPersona($payload['persona_id'])
                    : null,
            ];
        });
    }

    private function normalizePayload(array $input): array
    {
        $referencia = trim((string) ($input['referencia'] ?? ''));
        $estadoPago = strtoupper(trim((string) ($input['estado_pago'] ?? 'PAGADO')));

        return [
            'venta_id' => !empty($input['venta_id']) ? (int) $input['venta_id'] : null,
            'sede_id' => (int) ($input['sede_id'] ?? 0),
            'persona_id' => !empty($input['persona_id']) ? (int) $input['persona_id'] : null,
            'forma_pago' => strtoupper(trim((string) ($input['forma_pago'] ?? 'EFECTIVO'))),
            'estado_pago' => in_array($estadoPago, ['PAGADO', 'PENDIENTE', 'ABONADO'], true) ? $estadoPago : 'PAGADO',
            'membresia_id' => !empty($input['membresia_id']) ? (int) $input['membresia_id'] : null,
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

    private function prepararMembresiaExistente(array $payload): array
    {
        if (!$payload['persona_id']) {
            throw new RuntimeException('Para vender una membresia debes seleccionar un cliente valido');
        }

        $membresia = $this->puntoVentaQuery->findMembership($payload['membresia_id'], $payload['sede_id'] ?? null);
        if (!$membresia) {
            throw new RuntimeException('No se encontro la membresia seleccionada para la venta');
        }

        $persona = $this->puntoVentaQuery->findPersona($payload['persona_id']);
        if (!$persona) {
            throw new RuntimeException('No se encontro la persona seleccionada para registrar la membresia');
        }

        $total = round((float) $membresia['precio'], 2);

        return [
            'detalle' => [
                'tipo_detalle' => 'MEMBRESIA',
                'producto_id' => null,
                'membresia_id' => $membresia['id'],
                'descripcion' => $membresia['nombre'],
                'cantidad' => 1,
                'precio_unitario' => $membresia['precio'],
                'subtotal' => $total,
            ],
            'items' => [[
                'tipo_detalle' => 'MEMBRESIA',
                'membresia_id' => $membresia['id'],
                'nombre' => $membresia['nombre'],
                'cantidad' => 1,
                'precio_unitario' => $membresia['precio'],
                'subtotal' => $total,
            ]],
            'total' => $total,
            'saldo_pendiente' => $payload['estado_pago'] === 'PENDIENTE' ? $total : 0,
            'membresia' => [
                'id' => $membresia['id'],
                'nombre' => $membresia['nombre'],
                'descripcion' => $membresia['descripcion'],
                'precio' => $membresia['precio'],
                'duracion_dias' => $membresia['duracion_dias'],
                'cliente_nombre' => $persona['nombres'],
            ],
        ];
    }

    private function prepararConsumo(array $payload, Request $request): array
    {
        $itemsProcesados = [];
        $movimientosRegistrados = 0;
        $total = 0;
        $referencia = $this->buildConsumoReference($payload['referencia']);

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
            $descontarStock = !$this->hasExistingPosStockMovement($payload);

            if (!$descontarStock) {
                $movimientosItem = [];
            } elseif (!empty($producto['maneja_lotes'])) {
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
                        'observacion' => $this->buildObservacion($payload, $referencia),
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
                    'observacion' => $this->buildObservacion($payload, $referencia),
                ], $request);
            }

            $subtotal = round($item['cantidad'] * $precioUnitario, 2);
            $total += $subtotal;
            $movimientosRegistrados += count($movimientosItem);

            $itemsProcesados[] = [
                'tipo_detalle' => 'PRODUCTO',
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
            'referencia' => $referencia,
            'detalles' => array_map(fn ($item) => [
                'tipo_detalle' => 'PRODUCTO',
                'producto_id' => $item['producto_id'],
                'membresia_id' => null,
                'descripcion' => $item['nombre'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'subtotal' => $item['subtotal'],
            ], $itemsProcesados),
            'items' => array_map(fn ($item) => [
                'tipo_detalle' => 'PRODUCTO',
                'producto_id' => $item['producto_id'],
                'codigo' => $item['codigo'],
                'nombre' => $item['nombre'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'subtotal' => $item['subtotal'],
            ], $itemsProcesados),
            'movimientos_registrados' => $movimientosRegistrados,
            'total' => round($total, 2),
            'saldo_pendiente' => $payload['estado_pago'] === 'PENDIENTE' ? round($total, 2) : 0,
        ];
    }

    private function prepararMembresia(array $payload, Request $request): array
    {
        if (!$payload['persona_id']) {
            throw new RuntimeException('Para vender una membresia debes seleccionar un cliente valido');
        }

        $membresia = $this->puntoVentaQuery->findMembership($payload['membresia_id'], $payload['sede_id'] ?? null);
        if (!$membresia) {
            throw new RuntimeException('No se encontro la membresia seleccionada para la venta');
        }

        $persona = $this->puntoVentaQuery->findPersona($payload['persona_id']);
        if (!$persona) {
            throw new RuntimeException('No se encontro la persona seleccionada para registrar la membresia');
        }

        $socio = $this->ensureSocio($payload['persona_id'], $payload['sede_id']);
        $estadoActivoId = DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');
        $ultimaAsignacion = $this->puntoVentaQuery->latestMembershipAssignment($socio['id']);
        $fechaInicio = $this->businessToday();

        if ($ultimaAsignacion && Carbon::parse($ultimaAsignacion['fecha_fin'])->gte($this->businessToday())) {
            $fechaInicio = Carbon::parse($ultimaAsignacion['fecha_fin'])->addDay();
        }

        $fechaFin = (clone $fechaInicio)->addDays(max($membresia['duracion_dias'] - 1, 0));

        $asignacionId = DB::table('socios.socio_membresias')->insertGetId([
            'socio_id' => $socio['id'],
            'membresia_id' => $membresia['id'],
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $fechaFin->toDateString(),
            'estado_id' => $estadoActivoId,
            'cedula' => $persona['numero_identificacion'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $total = round((float) $membresia['precio'], 2);
        $referencia = $payload['referencia'] . '-MEM';
        $observacion = trim(sprintf(
            '%s | Membresia %s activada para %s',
            $payload['observacion'],
            $membresia['nombre'],
            $persona['nombres']
        ));

        return [
            'referencia' => $referencia,
            'detalle' => [
                'tipo_detalle' => 'MEMBRESIA',
                'producto_id' => null,
                'membresia_id' => $membresia['id'],
                'descripcion' => $membresia['nombre'],
                'cantidad' => 1,
                'precio_unitario' => $membresia['precio'],
                'subtotal' => $total,
                'fecha_inicio' => $fechaInicio->toDateString(),
                'fecha_fin' => $fechaFin->toDateString(),
            ],
            'items' => [[
                'tipo_detalle' => 'MEMBRESIA',
                'membresia_id' => $membresia['id'],
                'nombre' => $membresia['nombre'],
                'cantidad' => 1,
                'precio_unitario' => $membresia['precio'],
                'subtotal' => $total,
                'fecha_inicio' => $fechaInicio->toDateString(),
                'fecha_fin' => $fechaFin->toDateString(),
            ]],
            'total' => $total,
            'saldo_pendiente' => $payload['estado_pago'] === 'PENDIENTE' ? $total : 0,
            'membresia' => [
                'id' => $membresia['id'],
                'nombre' => $membresia['nombre'],
                'descripcion' => $membresia['descripcion'],
                'precio' => $membresia['precio'],
                'duracion_dias' => $membresia['duracion_dias'],
                'cliente_nombre' => $persona['nombres'],
            ],
            'asignacion' => [
                'id' => $asignacionId,
                'socio_id' => $socio['id'],
                'codigo_socio' => $socio['codigo_socio'],
                'fecha_inicio' => $fechaInicio->toDateString(),
                'fecha_fin' => $fechaFin->toDateString(),
            ],
        ];
    }

    private function ensureSocio(int $personaId, int $sedeId): array
    {
        $socio = $this->puntoVentaQuery->findSocioByPersona($personaId);
        if ($socio) {
            return $socio;
        }

        $estadoActivoId = DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');
        $ultimoSocioId = DB::table('socios.socios')->max('id') ?? 0;
        $codigoSocio = 'SOC-' . str_pad($ultimoSocioId + 1, 4, '0', STR_PAD_LEFT);

        $socioId = DB::table('socios.socios')->insertGetId([
            'persona_id' => $personaId,
            'sede_id' => $sedeId,
            'codigo_socio' => $codigoSocio,
            'fecha_alta' => $this->businessToday()->toDateString(),
            'estado_id' => $estadoActivoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tipoSocioId = DB::table('core.persona_tipos')->where('codigo', 'SOCIO')->value('id');
        if ($tipoSocioId) {
            $existsTipo = DB::table('core.persona_tipo_detalle')
                ->where('persona_id', $personaId)
                ->where('tipo_id', $tipoSocioId)
                ->exists();

            if (!$existsTipo) {
                DB::table('core.persona_tipo_detalle')->insert([
                    'persona_id' => $personaId,
                    'tipo_id' => $tipoSocioId,
                    'activo' => true,
                    'fecha_inicio' => $this->businessToday()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return [
            'id' => (int) $socioId,
            'persona_id' => $personaId,
            'codigo_socio' => $codigoSocio,
            'sede_id' => $sedeId,
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

    private function buildObservacion(array $payload, ?string $referencia = null): string
    {
        $base = $payload['observacion'] !== '' ? $payload['observacion'] : 'Venta POS';
        $ref = $referencia ?: $payload['referencia'];

        return trim(sprintf(
            '%s | Ref: %s | Pago: %s',
            $base,
            $ref,
            $payload['estado_pago'] === 'PENDIENTE' ? 'PENDIENTE' : $payload['forma_pago']
        ));
    }

    private function hasExistingPosStockMovement(array $payload): bool
    {
        if (empty($payload['venta_id'])) {
            return false;
        }

        $referencia = $this->buildConsumoReference($payload['referencia']);

        return DB::table('inventario.movimientos_inventario')
            ->where('referencia_tipo', 'VENTA_POS')
            ->where('observacion', 'like', '%' . $referencia . '%')
            ->exists();
    }

    private function buildConsumoReference(string $referencia): string
    {
        return str_ends_with($referencia, '-CON') ? $referencia : $referencia . '-CON';
    }

    private function generateReference(): string
    {
        return 'POS-' . now()->format('Ymd-His-u');
    }
}
