<?php

namespace App\Services\Inventarios;

use App\Queries\Inventarios\ProductoMovimientoQuery;
use App\Queries\Inventarios\ProductoQuery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductoMovimientoService
{
    public function __construct(
        private ProductoMovimientoQuery $productoMovimientoQuery,
        private ProductoQuery $productoQuery,
        private AuditService $auditService
    ) {
    }

    public function all(array $filters = []): array
    {
        return $this->productoMovimientoQuery->search($filters);
    }

    public function registrarEntrada(array $input, Request $request): array
    {
        return $this->registrarMovimiento($input, $request, 'ENTRADA');
    }

    public function registrarSalida(array $input, Request $request): array
    {
        return $this->registrarMovimiento($input, $request, 'SALIDA');
    }

    public function registrarAjuste(array $input, Request $request): array
    {
        return $this->registrarMovimiento($input, $request, 'AJUSTE');
    }

    public function registrarBaja(array $input, Request $request): array
    {
        $input['tipo_movimiento'] = 'SALIDA';
        $input['motivo'] = $input['motivo'] ?? 'BAJA';

        return $this->registrarMovimiento($input, $request, 'SALIDA');
    }

    public function registrarTransferencia(array $input, Request $request): array
    {
        $productoId = (int) ($input['producto_id'] ?? 0);
        $sedeOrigenId = (int) ($input['sede_origen_id'] ?? 0);
        $sedeDestinoId = (int) ($input['sede_destino_id'] ?? 0);
        $loteOrigenId = !empty($input['lote_id']) ? (int) $input['lote_id'] : null;

        if ($sedeOrigenId === $sedeDestinoId) {
            throw new RuntimeException('La sede origen y la sede destino deben ser diferentes');
        }

        $producto = $this->productoQuery->find($productoId);
        if (!$producto) {
            throw new RuntimeException('Producto no encontrado');
        }

        $loteOrigen = null;
        if ($loteOrigenId) {
            $loteOrigen = DB::table('inventario.producto_lotes')
                ->where('id', $loteOrigenId)
                ->where('producto_id', $productoId)
                ->where('sede_id', $sedeOrigenId)
                ->first();

            if (!$loteOrigen) {
                throw new RuntimeException('El lote seleccionado no pertenece al producto y sede origen');
            }
        }

        $referenciaTransferencia = (int) now()->format('YmdHisv');
        $motivo = strtoupper(trim((string) ($input['motivo'] ?? 'TRANSFERENCIA_INTERNA')));
        $observacionBase = trim((string) ($input['observacion'] ?? ''));
        $observacion = trim($observacionBase . " | Transferencia {$sedeOrigenId} -> {$sedeDestinoId}");

        return DB::transaction(function () use (
            $input,
            $request,
            $producto,
            $sedeOrigenId,
            $sedeDestinoId,
            $loteOrigen,
            $referenciaTransferencia,
            $motivo,
            $observacion
        ) {
            $payloadSalida = [
                'producto_id' => (int) $input['producto_id'],
                'sede_id' => $sedeOrigenId,
                'lote_id' => $loteOrigen?->id,
                'tipo_movimiento' => 'TRANSFERENCIA_SALIDA',
                'motivo' => $motivo,
                'cantidad' => (float) $input['cantidad'],
                'costo_unitario' => isset($input['costo_unitario']) ? (float) $input['costo_unitario'] : null,
                'precio_unitario' => isset($input['precio_unitario']) ? (float) $input['precio_unitario'] : null,
                'referencia_tipo' => 'TRANSFERENCIA',
                'referencia_id' => $referenciaTransferencia,
                'observacion' => $observacion,
            ];

            $payloadEntrada = [
                'producto_id' => (int) $input['producto_id'],
                'sede_id' => $sedeDestinoId,
                'tipo_movimiento' => 'TRANSFERENCIA_ENTRADA',
                'motivo' => $motivo,
                'cantidad' => (float) $input['cantidad'],
                'costo_unitario' => isset($input['costo_unitario']) ? (float) $input['costo_unitario'] : null,
                'precio_unitario' => isset($input['precio_unitario']) ? (float) $input['precio_unitario'] : null,
                'referencia_tipo' => 'TRANSFERENCIA',
                'referencia_id' => $referenciaTransferencia,
                'observacion' => $observacion,
            ];

            if ($loteOrigen) {
                $payloadEntrada['codigo_lote'] = $loteOrigen->codigo_lote;
                $payloadEntrada['fecha_elaboracion'] = $loteOrigen->fecha_elaboracion;
                $payloadEntrada['fecha_vencimiento'] = $loteOrigen->fecha_vencimiento;
            }

            $salida = $this->registrarMovimiento($payloadSalida, $request, 'SALIDA');
            $entrada = $this->registrarMovimiento($payloadEntrada, $request, 'ENTRADA');

            return [
                'referencia_transferencia' => $referenciaTransferencia,
                'producto_id' => (int) $input['producto_id'],
                'producto_nombre' => $producto['nombre'],
                'sede_origen_id' => $sedeOrigenId,
                'sede_destino_id' => $sedeDestinoId,
                'cantidad' => (float) $input['cantidad'],
                'salida' => $salida,
                'entrada' => $entrada,
            ];
        });
    }

    public function registrarInventarioInicial(array $input, Request $request): array
    {
        $productoId = (int) ($input['producto_id'] ?? 0);
        $producto = $this->productoQuery->find($productoId);

        if (!$producto) {
            throw new RuntimeException('Producto no encontrado');
        }

        if (empty($producto['controla_stock'])) {
            throw new RuntimeException('El producto seleccionado no maneja control de stock');
        }

        $payload = $this->normalizeInventarioInicialPayload($input, $producto);

        return DB::transaction(function () use ($payload, $producto, $request) {
            $stock = DB::table('inventario.producto_stock_sede')
                ->where('producto_id', $payload['producto_id'])
                ->where('sede_id', $payload['sede_id'])
                ->first();

            $stockId = $stock?->id;
            $stockAnteriorReal = (float) ($stock?->stock_actual ?? 0);
            $stockAnterior = $stockAnteriorReal;
            $stockReservado = (float) ($stock?->stock_reservado ?? 0);
            $stockMinimo = (float) $payload['stock_minimo'];
            $ubicacion = $payload['ubicacion'];
            $createdBy = $request->user()?->id;
            $esEdicion = false;

            if ($stock) {
                $this->ensureInventarioInicialEditable($payload['producto_id'], $payload['sede_id']);
                $esEdicion = $this->hasMovimientosIniciales($payload['producto_id'], $payload['sede_id']);
            }

            if (!$stock) {
                $stockId = (int) DB::table('inventario.producto_stock_sede')->insertGetId([
                    'producto_id' => $payload['producto_id'],
                    'sede_id' => $payload['sede_id'],
                    'stock_actual' => 0,
                    'stock_reservado' => 0,
                    'stock_disponible' => 0,
                    'stock_minimo' => $stockMinimo,
                    'ubicacion' => $ubicacion,
                    'estado' => 1,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                if ($esEdicion) {
                    $this->resetInventarioInicialExistente($payload['producto_id'], $payload['sede_id'], $stockId, $createdBy);
                    $stockAnterior = 0;
                }

                DB::table('inventario.producto_stock_sede')
                    ->where('id', $stockId)
                    ->update([
                        'stock_actual' => $stockAnterior,
                        'stock_disponible' => max(0, $stockAnterior - $stockReservado),
                        'stock_minimo' => $stockMinimo,
                        'ubicacion' => $ubicacion,
                        'updated_by' => $createdBy,
                        'updated_at' => now(),
                    ]);
            }

            $stockNuevo = $stockAnterior;
            $movimientosIds = [];
            $lotesProcesados = [];

            if ($payload['maneja_lotes']) {
                foreach ($payload['lotes'] as $loteInput) {
                    $cantidad = (float) $loteInput['cantidad_inicial'];
                    $lote = DB::table('inventario.producto_lotes')
                        ->where('producto_id', $payload['producto_id'])
                        ->where('sede_id', $payload['sede_id'])
                        ->where('codigo_lote', $loteInput['codigo_lote'])
                        ->where('estado', 1)
                        ->first();

                    $loteId = $lote?->id;

                    if ($lote) {
                        if (
                            !empty($loteInput['fecha_elaboracion']) &&
                            !empty($lote->fecha_elaboracion) &&
                            $lote->fecha_elaboracion !== $loteInput['fecha_elaboracion']
                        ) {
                            throw new RuntimeException("El lote {$loteInput['codigo_lote']} ya existe con una fecha de elaboración diferente");
                        }

                        if (
                            !empty($loteInput['fecha_vencimiento']) &&
                            !empty($lote->fecha_vencimiento) &&
                            $lote->fecha_vencimiento !== $loteInput['fecha_vencimiento']
                        ) {
                            throw new RuntimeException("El lote {$loteInput['codigo_lote']} ya existe con una fecha de vencimiento diferente");
                        }

                        DB::table('inventario.producto_lotes')
                            ->where('id', $loteId)
                            ->update([
                                'fecha_elaboracion' => $lote->fecha_elaboracion ?: $loteInput['fecha_elaboracion'],
                                'fecha_vencimiento' => $lote->fecha_vencimiento ?: $loteInput['fecha_vencimiento'],
                                'stock_actual' => (float) $lote->stock_actual + $cantidad,
                                'updated_by' => $createdBy,
                                'updated_at' => now(),
                            ]);
                    } else {
                        $loteId = (int) DB::table('inventario.producto_lotes')->insertGetId([
                            'producto_id' => $payload['producto_id'],
                            'sede_id' => $payload['sede_id'],
                            'codigo_lote' => $loteInput['codigo_lote'],
                            'fecha_elaboracion' => $loteInput['fecha_elaboracion'],
                            'fecha_vencimiento' => $loteInput['fecha_vencimiento'],
                            'stock_actual' => $cantidad,
                            'estado' => 1,
                            'created_by' => $createdBy,
                            'updated_by' => $createdBy,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $stockPrevioMovimiento = $stockNuevo;
                    $stockNuevo += $cantidad;
                    $movimientoId = (int) DB::table('inventario.movimientos_inventario')->insertGetId([
                        'producto_id' => $payload['producto_id'],
                        'sede_id' => $payload['sede_id'],
                        'lote_id' => $loteId,
                        'tipo_movimiento' => 'ENTRADA',
                        'motivo' => 'INVENTARIO_INICIAL',
                        'cantidad' => $cantidad,
                        'stock_anterior' => $stockPrevioMovimiento,
                        'stock_nuevo' => $stockNuevo,
                        'costo_unitario' => $payload['costo_unitario'],
                        'precio_unitario' => $payload['precio_unitario'],
                        'referencia_tipo' => 'INVENTARIO_INICIAL',
                        'referencia_id' => $stockId,
                        'observacion' => $payload['observacion'],
                        'created_by' => $createdBy,
                        'created_at' => now(),
                    ]);

                    $movimientosIds[] = $movimientoId;
                    $lotesProcesados[] = [
                        'id' => $loteId,
                        'codigo_lote' => $loteInput['codigo_lote'],
                        'cantidad_inicial' => $cantidad,
                        'fecha_elaboracion' => $loteInput['fecha_elaboracion'],
                        'fecha_vencimiento' => $loteInput['fecha_vencimiento'],
                    ];
                }
            } else {
                $cantidad = $payload['cantidad'];
                $stockNuevo += $cantidad;
                $movimientoId = (int) DB::table('inventario.movimientos_inventario')->insertGetId([
                    'producto_id' => $payload['producto_id'],
                    'sede_id' => $payload['sede_id'],
                    'lote_id' => null,
                    'tipo_movimiento' => 'ENTRADA',
                    'motivo' => 'INVENTARIO_INICIAL',
                    'cantidad' => $cantidad,
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo' => $stockNuevo,
                    'costo_unitario' => $payload['costo_unitario'],
                    'precio_unitario' => $payload['precio_unitario'],
                    'referencia_tipo' => 'INVENTARIO_INICIAL',
                    'referencia_id' => $stockId,
                    'observacion' => $payload['observacion'],
                    'created_by' => $createdBy,
                    'created_at' => now(),
                ]);

                $movimientosIds[] = $movimientoId;
            }

            DB::table('inventario.producto_stock_sede')
                ->where('id', $stockId)
                ->update([
                    'stock_actual' => $stockNuevo,
                    'stock_disponible' => max(0, $stockNuevo - $stockReservado),
                    'stock_minimo' => $stockMinimo,
                    'ubicacion' => $ubicacion,
                    'updated_by' => $createdBy,
                    'updated_at' => now(),
                ]);

            $auditPayload = [
                'producto_id' => $payload['producto_id'],
                'producto_nombre' => $producto['nombre'],
                'sede_id' => $payload['sede_id'],
                'stock_anterior' => $stockAnteriorReal,
                'stock_nuevo' => $stockNuevo,
                'movimientos_registrados' => count($movimientosIds),
                'maneja_lotes' => $payload['maneja_lotes'],
                'lotes' => $lotesProcesados,
            ];

            $auditMeta = [
                'modulo' => 'inventarios',
                'accion' => $esEdicion
                    ? 'actualizar_inventario_inicial_producto'
                    : 'registrar_inventario_inicial_producto',
                'sede_id' => $payload['sede_id'],
            ];

            if ($esEdicion) {
                $this->auditService->updated(
                    $request,
                    'inventario_inicial_producto',
                    $stockId,
                    [
                        'producto_id' => $payload['producto_id'],
                        'sede_id' => $payload['sede_id'],
                        'stock_anterior' => $stockAnteriorReal,
                    ],
                    $auditPayload,
                    $auditMeta
                );
            } else {
                $this->auditService->created(
                    $request,
                    'inventario_inicial_producto',
                    $stockId,
                    $auditPayload,
                    $auditMeta
                );
            }

            return [
                'producto' => $this->productoQuery->find($payload['producto_id']),
                'stock_id' => $stockId,
                'stock_anterior' => $stockAnteriorReal,
                'stock_nuevo' => $stockNuevo,
                'movimientos_registrados' => count($movimientosIds),
                'lotes_registrados' => count($lotesProcesados),
                'editado' => $esEdicion,
            ];
        });
    }

    public function stockPorProducto(int $productoId): array
    {
        $rows = DB::table('inventario.producto_stock_sede as ps')
            ->join('core.sedes as s', 'ps.sede_id', '=', 's.id')
            ->where('ps.producto_id', $productoId)
            ->where('ps.estado', 1)
            ->select(
                'ps.id',
                'ps.producto_id',
                'ps.sede_id',
                's.nombre as sede_nombre',
                'ps.stock_actual as cantidad',
                'ps.stock_actual',
                'ps.stock_minimo',
                'ps.ubicacion'
            )
            ->orderBy('s.nombre')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        if (empty($rows)) {
            return [];
        }

        $sedeIds = array_values(array_unique(array_map(fn ($row) => (int) $row['sede_id'], $rows)));

        $lotesPorSede = DB::table('inventario.producto_lotes')
            ->where('producto_id', $productoId)
            ->whereIn('sede_id', $sedeIds)
            ->where('estado', 1)
            ->orderByRaw('coalesce(fecha_vencimiento, ?)', ['9999-12-31'])
            ->orderBy('codigo_lote')
            ->get([
                'id',
                'sede_id',
                'codigo_lote',
                'fecha_elaboracion',
                'fecha_vencimiento',
                'stock_actual as cantidad',
            ])
            ->groupBy('sede_id');

        $stockInicialPorSede = DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->whereIn('sede_id', $sedeIds)
            ->where('tipo_movimiento', 'ENTRADA')
            ->where('motivo', 'INVENTARIO_INICIAL')
            ->selectRaw('sede_id, coalesce(sum(cantidad), 0) as stock_inicial')
            ->groupBy('sede_id')
            ->pluck('stock_inicial', 'sede_id');

        $movimientosInicialesPorSede = DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->whereIn('sede_id', $sedeIds)
            ->where('tipo_movimiento', 'ENTRADA')
            ->where('motivo', 'INVENTARIO_INICIAL')
            ->selectRaw('sede_id, count(*) as movimientos_iniciales')
            ->groupBy('sede_id')
            ->pluck('movimientos_iniciales', 'sede_id');

        $movimientosPosterioresPorSede = DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->whereIn('sede_id', $sedeIds)
            ->selectRaw("
                sede_id,
                sum(
                    case
                        when tipo_movimiento = 'ENTRADA' and motivo = 'INVENTARIO_INICIAL' then 0
                        else 1
                    end
                ) as movimientos_posteriores
            ")
            ->groupBy('sede_id')
            ->pluck('movimientos_posteriores', 'sede_id');

        return array_map(function (array $row) use ($lotesPorSede, $stockInicialPorSede, $movimientosInicialesPorSede, $movimientosPosterioresPorSede) {
            $lotes = collect($lotesPorSede->get($row['sede_id'], []))
                ->map(fn ($lote) => [
                    'id' => (int) $lote->id,
                    'codigo_lote' => $lote->codigo_lote,
                    'fecha_elaboracion' => $lote->fecha_elaboracion,
                    'fecha_vencimiento' => $lote->fecha_vencimiento,
                    'cantidad' => (float) $lote->cantidad,
                ])
                ->values()
                ->all();

            $tieneInventarioInicial = ((int) ($movimientosInicialesPorSede[$row['sede_id']] ?? 0)) > 0;
            $sinMovimientosPosteriores = ((int) ($movimientosPosterioresPorSede[$row['sede_id']] ?? 0)) === 0;
            $inventarioInicialEditable = $tieneInventarioInicial && $sinMovimientosPosteriores;

            return [
                'id' => (int) $row['id'],
                'producto_id' => (int) $row['producto_id'],
                'sede_id' => (int) $row['sede_id'],
                'sede_nombre' => $row['sede_nombre'],
                'cantidad' => (float) $row['cantidad'],
                'stock_actual' => (float) $row['stock_actual'],
                'stock_inicial' => (float) ($stockInicialPorSede[$row['sede_id']] ?? 0),
                'stock_minimo' => (float) $row['stock_minimo'],
                'ubicacion' => $row['ubicacion'],
                'tiene_inventario_inicial' => $tieneInventarioInicial,
                'inventario_inicial_editable' => $inventarioInicialEditable,
                'inventario_inicial_eliminable' => $inventarioInicialEditable,
                'cantidad_lotes' => count($lotes),
                'lotes' => $lotes,
            ];
        }, $rows);
    }

    public function eliminarInventarioInicial(int $productoId, int $stockId, Request $request): array
    {
        $stock = DB::table('inventario.producto_stock_sede')
            ->where('id', $stockId)
            ->where('producto_id', $productoId)
            ->first();

        if (!$stock) {
            throw new RuntimeException('No se encontró el stock seleccionado para este producto');
        }

        $sedeId = (int) $stock->sede_id;
        $before = collect($this->stockPorProducto($productoId))->firstWhere('id', $stockId);

        if (!$before) {
            throw new RuntimeException('No se pudo leer el detalle de la bodega seleccionada');
        }

        $this->ensureInventarioInicialEditable($productoId, $sedeId);

        if (!$this->hasMovimientosIniciales($productoId, $sedeId)) {
            throw new RuntimeException('La bodega seleccionada no tiene un inventario inicial que se pueda eliminar');
        }

        return DB::transaction(function () use ($productoId, $stockId, $stock, $sedeId, $before, $request) {
            $userId = $request->user()?->id;

            $this->resetInventarioInicialExistente($productoId, $sedeId, $stockId, $userId);

            DB::table('inventario.producto_stock_sede')
                ->where('id', $stockId)
                ->update([
                    'estado' => 0,
                    'stock_minimo' => 0,
                    'ubicacion' => null,
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            $after = [
                'id' => $stockId,
                'producto_id' => $productoId,
                'sede_id' => $sedeId,
                'estado' => 0,
                'stock_actual' => 0,
            ];

            $this->auditService->deleted(
                $request,
                'producto_stock_sede',
                $stockId,
                $before,
                $after,
                [
                    'modulo' => 'inventarios',
                    'accion' => 'eliminar_inventario_inicial_producto',
                    'sede_id' => $sedeId,
                ]
            );

            return [
                'stock_id' => $stockId,
                'producto_id' => $productoId,
                'sede_id' => $sedeId,
            ];
        });
    }

    private function registrarMovimiento(array $input, Request $request, string $tipoEsperado): array
    {
        $payload = $this->normalizePayload($input, $tipoEsperado);
        $producto = $this->productoQuery->find($payload['producto_id']);

        if (!$producto) {
            throw new RuntimeException('Producto no encontrado');
        }

        $stock = DB::table('inventario.producto_stock_sede')
            ->where('producto_id', $payload['producto_id'])
            ->where('sede_id', $payload['sede_id'])
            ->first();

        if (!$stock) {
            $stockId = DB::table('inventario.producto_stock_sede')->insertGetId([
                'producto_id' => $payload['producto_id'],
                'sede_id' => $payload['sede_id'],
                'stock_actual' => 0,
                'stock_reservado' => 0,
                'stock_disponible' => 0,
                'stock_minimo' => 0,
                'ubicacion' => 'ALMACEN PRINCIPAL',
                'estado' => 1,
                'created_by' => $request->user()?->id ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stock = DB::table('inventario.producto_stock_sede')->where('id', $stockId)->first();
        }

        if (!empty($producto['maneja_lotes'])) {
            if ($payload['tipo_movimiento'] === 'ENTRADA') {
                if (empty($payload['lote_id']) && empty($payload['codigo_lote'])) {
                    throw new RuntimeException('Debes indicar un lote para registrar el ingreso');
                }
            } elseif (empty($payload['lote_id'])) {
                throw new RuntimeException('El producto requiere lote para registrar movimientos');
            }
        }

        return DB::transaction(function () use ($payload, $request, $stock, $producto) {
            $stockAnterior = (float) $stock->stock_actual;
            $delta = $this->calculateDelta($payload['tipo_movimiento'], (float) $payload['cantidad'], $payload['motivo']);
            $stockNuevo = $stockAnterior + $delta;
            [$loteId, $lote] = $this->resolveLoteForMovimiento($payload, $producto, (int) ($request->user()?->id ?? 0));

            if ($stockNuevo < 0) {
                throw new RuntimeException('Stock insuficiente para realizar el movimiento');
            }

            DB::table('inventario.producto_stock_sede')
                ->where('id', $stock->id)
                ->update([
                    'stock_actual' => $stockNuevo,
                    'stock_disponible' => max(0, $stockNuevo - (float) $stock->stock_reservado),
                    'updated_by' => $request->user()?->id,
                    'updated_at' => now(),
                ]);

            if ($loteId && $lote) {
                if ($lote->fecha_vencimiento && $payload['tipo_movimiento'] === 'SALIDA' && $lote->fecha_vencimiento < now()->toDateString()) {
                    throw new RuntimeException('No se puede dar salida a un lote vencido');
                }

                $loteNuevo = (float) $lote->stock_actual + $delta;
                if ($loteNuevo < 0) {
                    throw new RuntimeException('Stock insuficiente en el lote seleccionado');
                }

                DB::table('inventario.producto_lotes')
                    ->where('id', $loteId)
                    ->update([
                        'stock_actual' => $loteNuevo,
                        'updated_by' => $request->user()?->id,
                        'updated_at' => now(),
                    ]);
            }

            $id = (int) DB::table('inventario.movimientos_inventario')->insertGetId([
                'producto_id' => $payload['producto_id'],
                'sede_id' => $payload['sede_id'],
                'lote_id' => $loteId,
                'tipo_movimiento' => $payload['tipo_movimiento'],
                'motivo' => $payload['motivo'],
                'cantidad' => $payload['cantidad'],
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'costo_unitario' => $payload['costo_unitario'],
                'precio_unitario' => $payload['precio_unitario'],
                'referencia_tipo' => $payload['referencia_tipo'],
                'referencia_id' => $payload['referencia_id'],
                'observacion' => $payload['observacion'],
                'created_by' => $request->user()?->id,
                'created_at' => now(),
            ]);

            $movimiento = DB::table('inventario.movimientos_inventario')
                ->where('id', $id)
                ->first();

            $this->auditService->created($request, 'movimientos_inventario', $id, [
                'producto_id' => $payload['producto_id'],
                'producto_nombre' => $producto['nombre'],
                'tipo_movimiento' => $payload['tipo_movimiento'],
                'motivo' => $payload['motivo'],
                'cantidad' => $payload['cantidad'],
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'referencia_tipo' => $payload['referencia_tipo'],
                'referencia_id' => $payload['referencia_id'],
            ], [
                'modulo' => 'inventarios',
                'accion' => 'registrar_movimiento_producto',
                'sede_id' => $payload['sede_id'],
            ]);

            return [
                'id' => $id,
                'producto_id' => (int) $movimiento->producto_id,
                'sede_id' => (int) $movimiento->sede_id,
                'lote_id' => $movimiento->lote_id ? (int) $movimiento->lote_id : null,
                'tipo_movimiento' => $movimiento->tipo_movimiento,
                'motivo' => $movimiento->motivo,
                'cantidad' => $movimiento->cantidad,
                'stock_anterior' => $movimiento->stock_anterior,
                'stock_nuevo' => $movimiento->stock_nuevo,
                'created_at' => $movimiento->created_at,
            ];
        });
    }

    private function normalizeInventarioInicialPayload(array $input, array $producto): array
    {
        $manejaLotes = !empty($producto['maneja_lotes']);
        $manejaVencimiento = !empty($producto['maneja_vencimiento']);
        $lotes = array_values(array_filter($input['lotes'] ?? [], fn ($row) => is_array($row)));
        $cantidad = (float) ($input['cantidad'] ?? 0);

        if ($manejaLotes && empty($lotes)) {
            throw new RuntimeException('Debes agregar al menos un lote para este producto');
        }

        if (!$manejaLotes && $cantidad <= 0) {
            throw new RuntimeException('La cantidad inicial debe ser mayor a 0');
        }

        $normalizedLotes = [];

        foreach ($lotes as $index => $row) {
            $codigo = trim((string) ($row['codigo_lote'] ?? ''));
            $fechaElaboracion = !empty($row['fecha_elaboracion']) ? (string) $row['fecha_elaboracion'] : null;
            $fechaVencimiento = !empty($row['fecha_vencimiento']) ? (string) $row['fecha_vencimiento'] : null;
            $cantidadLote = (float) ($row['cantidad_inicial'] ?? 0);

            if ($codigo === '') {
                throw new RuntimeException('Cada lote debe tener código');
            }

            if (!$fechaElaboracion) {
                throw new RuntimeException("El lote {$codigo} debe tener fecha de elaboración");
            }

            if ($manejaVencimiento && !$fechaVencimiento) {
                throw new RuntimeException("El lote {$codigo} debe tener fecha de vencimiento");
            }

            if ($fechaVencimiento && $fechaElaboracion > $fechaVencimiento) {
                throw new RuntimeException("La fecha de vencimiento del lote {$codigo} no puede ser menor a la de elaboración");
            }

            if ($cantidadLote <= 0) {
                throw new RuntimeException("La cantidad del lote {$codigo} debe ser mayor a 0");
            }

            $normalizedLotes[] = [
                'codigo_lote' => $codigo,
                'fecha_elaboracion' => $fechaElaboracion,
                'fecha_vencimiento' => $fechaVencimiento,
                'cantidad_inicial' => $cantidadLote,
                'orden' => $index,
            ];
        }

        return [
            'producto_id' => (int) ($input['producto_id'] ?? 0),
            'sede_id' => (int) ($input['sede_id'] ?? 0),
            'cantidad' => $manejaLotes
                ? array_reduce($normalizedLotes, fn ($carry, $row) => $carry + (float) $row['cantidad_inicial'], 0)
                : $cantidad,
            'stock_minimo' => (float) ($input['stock_minimo'] ?? 0),
            'ubicacion' => trim((string) ($input['ubicacion'] ?? 'ALMACEN PRINCIPAL')),
            'costo_unitario' => isset($input['costo_unitario']) ? (float) $input['costo_unitario'] : null,
            'precio_unitario' => isset($input['precio_unitario']) ? (float) $input['precio_unitario'] : null,
            'observacion' => trim((string) ($input['observacion'] ?? 'Registro inicial de inventario')),
            'maneja_lotes' => $manejaLotes,
            'lotes' => $normalizedLotes,
        ];
    }

    private function ensureInventarioInicialEditable(int $productoId, int $sedeId): void
    {
        $movimientosPosteriores = DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where(function ($query) {
                $query->where('tipo_movimiento', '<>', 'ENTRADA')
                    ->orWhere('motivo', '<>', 'INVENTARIO_INICIAL');
            })
            ->count();

        if ($movimientosPosteriores > 0) {
            throw new RuntimeException(
                'Este inventario inicial ya tiene movimientos posteriores y no se puede modificar desde esta pantalla'
            );
        }
    }

    private function hasMovimientosIniciales(int $productoId, int $sedeId): bool
    {
        return DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('tipo_movimiento', 'ENTRADA')
            ->where('motivo', 'INVENTARIO_INICIAL')
            ->exists();
    }

    private function resetInventarioInicialExistente(int $productoId, int $sedeId, int $stockId, ?int $userId): void
    {
        DB::table('inventario.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('tipo_movimiento', 'ENTRADA')
            ->where('motivo', 'INVENTARIO_INICIAL')
            ->delete();

        DB::table('inventario.producto_lotes')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->delete();

        DB::table('inventario.producto_stock_sede')
            ->where('id', $stockId)
            ->update([
                'stock_actual' => 0,
                'stock_disponible' => 0,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);
    }

    private function normalizePayload(array $input, string $tipoEsperado): array
    {
        $tipo = $input['tipo_movimiento'] ?? $tipoEsperado;

        return [
            'producto_id' => (int) ($input['producto_id'] ?? 0),
            'sede_id' => (int) ($input['sede_id'] ?? 0),
            'lote_id' => !empty($input['lote_id']) ? (int) $input['lote_id'] : null,
            'codigo_lote' => !empty($input['codigo_lote']) ? trim((string) $input['codigo_lote']) : null,
            'fecha_elaboracion' => !empty($input['fecha_elaboracion']) ? (string) $input['fecha_elaboracion'] : null,
            'fecha_vencimiento' => !empty($input['fecha_vencimiento']) ? (string) $input['fecha_vencimiento'] : null,
            'tipo_movimiento' => $tipo,
            'motivo' => strtoupper(trim((string) ($input['motivo'] ?? 'GENERAL'))),
            'cantidad' => (float) ($input['cantidad'] ?? 0),
            'costo_unitario' => isset($input['costo_unitario']) ? (float) $input['costo_unitario'] : null,
            'precio_unitario' => isset($input['precio_unitario']) ? (float) $input['precio_unitario'] : null,
            'referencia_tipo' => $input['referencia_tipo'] ?? null,
            'referencia_id' => !empty($input['referencia_id']) ? (int) $input['referencia_id'] : null,
            'observacion' => trim((string) ($input['observacion'] ?? '')),
        ];
    }

    private function calculateDelta(string $tipo, float $cantidad, string $motivo): float
    {
        return match ($tipo) {
            'ENTRADA', 'TRANSFERENCIA_ENTRADA' => $cantidad,
            'SALIDA', 'TRANSFERENCIA_SALIDA' => -$cantidad,
            'AJUSTE' => in_array($motivo, ['AJUSTE_POSITIVO', 'REGULARIZACION_POSITIVA'], true) ? $cantidad : -$cantidad,
            default => throw new RuntimeException('Tipo de movimiento no soportado'),
        };
    }

    private function resolveLoteForMovimiento(array $payload, array $producto, int $userId): array
    {
        if (empty($producto['maneja_lotes'])) {
            return [null, null];
        }

        if (!empty($payload['lote_id'])) {
            $lote = DB::table('inventario.producto_lotes')
                ->where('id', $payload['lote_id'])
                ->where('producto_id', $payload['producto_id'])
                ->where('sede_id', $payload['sede_id'])
                ->first();

            if (!$lote) {
                throw new RuntimeException('Lote no encontrado para el producto y sede');
            }

            return [(int) $lote->id, $lote];
        }

        if ($payload['tipo_movimiento'] !== 'ENTRADA' || empty($payload['codigo_lote'])) {
            return [null, null];
        }

        $lote = DB::table('inventario.producto_lotes')
            ->where('producto_id', $payload['producto_id'])
            ->where('sede_id', $payload['sede_id'])
            ->where('codigo_lote', $payload['codigo_lote'])
            ->where('estado', 1)
            ->first();

        if ($lote) {
            if (
                !empty($payload['fecha_elaboracion']) &&
                !empty($lote->fecha_elaboracion) &&
                $lote->fecha_elaboracion !== $payload['fecha_elaboracion']
            ) {
                throw new RuntimeException("El lote {$payload['codigo_lote']} ya existe con otra fecha de elaboración");
            }

            if (
                !empty($payload['fecha_vencimiento']) &&
                !empty($lote->fecha_vencimiento) &&
                $lote->fecha_vencimiento !== $payload['fecha_vencimiento']
            ) {
                throw new RuntimeException("El lote {$payload['codigo_lote']} ya existe con otra fecha de vencimiento");
            }

            return [(int) $lote->id, $lote];
        }

        if (!empty($producto['maneja_vencimiento']) && empty($payload['fecha_vencimiento'])) {
            throw new RuntimeException('El producto requiere fecha de vencimiento para el nuevo lote');
        }

        $loteId = (int) DB::table('inventario.producto_lotes')->insertGetId([
            'producto_id' => $payload['producto_id'],
            'sede_id' => $payload['sede_id'],
            'codigo_lote' => $payload['codigo_lote'],
            'fecha_elaboracion' => $payload['fecha_elaboracion'],
            'fecha_vencimiento' => $payload['fecha_vencimiento'],
            'stock_actual' => 0,
            'estado' => 1,
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nuevoLote = DB::table('inventario.producto_lotes')->where('id', $loteId)->first();

        return [$loteId, $nuevoLote];
    }
}
