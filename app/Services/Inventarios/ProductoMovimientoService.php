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
    private const STOCK_LOCK = 6301;
    private const LOTE_LOCK = 6302;
    private const MOVIMIENTO_LOCK = 6303;

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
            $stock = DB::table('train_gimnasio.producto_stock_sede')
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
                $stockId = $this->nextId('train_gimnasio.producto_stock_sede', self::STOCK_LOCK);

                DB::table('train_gimnasio.producto_stock_sede')->insert([
                    'id' => $stockId,
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

                DB::table('train_gimnasio.producto_stock_sede')
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
                    $lote = DB::table('train_gimnasio.producto_lotes')
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

                        DB::table('train_gimnasio.producto_lotes')
                            ->where('id', $loteId)
                            ->update([
                                'fecha_elaboracion' => $lote->fecha_elaboracion ?: $loteInput['fecha_elaboracion'],
                                'fecha_vencimiento' => $lote->fecha_vencimiento ?: $loteInput['fecha_vencimiento'],
                                'stock_actual' => (float) $lote->stock_actual + $cantidad,
                                'updated_by' => $createdBy,
                                'updated_at' => now(),
                            ]);
                    } else {
                        $loteId = $this->nextId('train_gimnasio.producto_lotes', self::LOTE_LOCK);

                        DB::table('train_gimnasio.producto_lotes')->insert([
                            'id' => $loteId,
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
                    $movimientoId = $this->nextId('train_gimnasio.movimientos_inventario', self::MOVIMIENTO_LOCK);

                    DB::table('train_gimnasio.movimientos_inventario')->insert([
                        'id' => $movimientoId,
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
                $movimientoId = $this->nextId('train_gimnasio.movimientos_inventario', self::MOVIMIENTO_LOCK);

                DB::table('train_gimnasio.movimientos_inventario')->insert([
                    'id' => $movimientoId,
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

            DB::table('train_gimnasio.producto_stock_sede')
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

    private function registrarMovimiento(array $input, Request $request, string $tipoEsperado): array
    {
        $payload = $this->normalizePayload($input, $tipoEsperado);
        $producto = $this->productoQuery->find($payload['producto_id']);

        if (!$producto) {
            throw new RuntimeException('Producto no encontrado');
        }

        $stock = DB::table('train_gimnasio.producto_stock_sede')
            ->where('producto_id', $payload['producto_id'])
            ->where('sede_id', $payload['sede_id'])
            ->first();

        if (!$stock) {
            throw new RuntimeException('No existe configuración de stock para la sede seleccionada');
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

            DB::table('train_gimnasio.producto_stock_sede')
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

                DB::table('train_gimnasio.producto_lotes')
                    ->where('id', $loteId)
                    ->update([
                        'stock_actual' => $loteNuevo,
                        'updated_by' => $request->user()?->id,
                        'updated_at' => now(),
                    ]);
            }

            $id = $this->nextId('train_gimnasio.movimientos_inventario', self::MOVIMIENTO_LOCK);

            DB::table('train_gimnasio.movimientos_inventario')->insert([
                'id' => $id,
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

            $movimiento = DB::table('train_gimnasio.movimientos_inventario')
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
        $movimientosPosteriores = DB::table('train_gimnasio.movimientos_inventario')
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
        return DB::table('train_gimnasio.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('tipo_movimiento', 'ENTRADA')
            ->where('motivo', 'INVENTARIO_INICIAL')
            ->exists();
    }

    private function resetInventarioInicialExistente(int $productoId, int $sedeId, int $stockId, ?int $userId): void
    {
        DB::table('train_gimnasio.movimientos_inventario')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('tipo_movimiento', 'ENTRADA')
            ->where('motivo', 'INVENTARIO_INICIAL')
            ->delete();

        DB::table('train_gimnasio.producto_lotes')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->delete();

        DB::table('train_gimnasio.producto_stock_sede')
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
            $lote = DB::table('train_gimnasio.producto_lotes')
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

        $lote = DB::table('train_gimnasio.producto_lotes')
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

        $loteId = $this->nextId('train_gimnasio.producto_lotes', self::LOTE_LOCK);

        DB::table('train_gimnasio.producto_lotes')->insert([
            'id' => $loteId,
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

        $nuevoLote = DB::table('train_gimnasio.producto_lotes')->where('id', $loteId)->first();

        return [$loteId, $nuevoLote];
    }

    private function nextId(string $table, int $lockKey): int
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

        return ((int) DB::table($table)->max('id')) + 1;
    }
}
