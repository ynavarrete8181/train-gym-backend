<?php

namespace App\Services\Inventario;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InventarioServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly EstadoCatalogoServicio $estados)
    {
    }

    public function listarCategorias(array $filtros) { return $this->listarSimple('inventario.categorias_producto', $filtros, ['nombre', 'descripcion']); }
    public function guardarCategoria(array $datos, ?int $id = null): object { return $this->guardar('inventario.categorias_producto', $datos, $id); }

    public function listarProveedores(array $filtros)
    {
        $query = DB::table('inventario.proveedores');
        $this->buscar($query, $filtros['busqueda'] ?? null, ['nombre', 'ruc', 'telefono', 'email']);
        $this->filtrarTexto($query, 'nombre', $filtros['nombre'] ?? null);
        $this->filtrarBooleano($query, 'activo', $filtros['estado'] ?? null);
        return $query->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarProveedor(array $datos, ?int $id = null): object { return $this->guardar('inventario.proveedores', $datos, $id); }

    public function listarProductos(array $filtros)
    {
        $query = DB::table('inventario.productos')
            ->leftJoin('inventario.categorias_producto', 'inventario.productos.categoria_id', '=', 'inventario.categorias_producto.id')
            ->leftJoin('inventario.proveedores', 'inventario.productos.proveedor_id', '=', 'inventario.proveedores.id')
            ->select('inventario.productos.*', 'inventario.categorias_producto.nombre as categoria_nombre', 'inventario.proveedores.nombre as proveedor_nombre');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['inventario.productos.codigo', 'inventario.productos.nombre', 'inventario.productos.marca', 'inventario.categorias_producto.nombre']);
        $this->filtrarTexto($query, 'inventario.productos.nombre', $filtros['producto'] ?? null);
        $this->filtrarTexto($query, 'inventario.categorias_producto.nombre', $filtros['categoria'] ?? null);
        $this->filtrarBooleano($query, 'inventario.productos.activo', $filtros['estado'] ?? null);

        $paginador = $query->orderBy('inventario.productos.nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);

        $paginador->getCollection()->transform(fn ($producto) => $this->completarProducto($producto));

        return $paginador;
    }

    public function guardarProducto(array $datos, ?int $id = null): object
    {
        return DB::transaction(function () use ($datos, $id): object {
            $preciosSede = $datos['precios_sede'] ?? [];
            $stocksSede = $datos['stocks_sede'] ?? [];
            $lotes = $datos['lotes'] ?? [];
            $productoAnterior = $id ? DB::table('inventario.productos')->where('id', $id)->first() : null;

            unset($datos['precios_sede'], $datos['stocks_sede'], $datos['lotes']);

            $id = $this->guardarRetornandoId('inventario.productos', $datos, $id);

            $sedes = DB::table('institucional.sedes')
                ->where('activo', true)
                ->where('maneja_inventario', true)
                ->get(['id_sede as id', 'nombre']);

            $preciosPorSede = collect($preciosSede)->keyBy('sede_id');
            $stocksPorSede = collect($stocksSede)->keyBy('sede_id');
            $stockInicialDefecto = 0;

            foreach ($sedes as $sede) {
                $precio = $preciosPorSede->get($sede->id);
                $stock = $stocksPorSede->get($sede->id);

                DB::table('inventario.producto_precios_sede')->updateOrInsert(
                    ['producto_id' => $id, 'sede_id' => $sede->id],
                    [
                        'precio' => $precio['precio'] ?? $datos['precio_venta'] ?? 0,
                        'activo' => array_key_exists('activo', (array) $precio) ? (bool) $precio['activo'] : true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $this->sincronizarStockInicialSede(
                    (int) $id,
                    (int) $sede->id,
                    (float) ($stock['stock_actual'] ?? $stockInicialDefecto),
                    (float) ($stock['stock_minimo'] ?? $datos['stock_minimo'] ?? 0),
                );
            }

            if (! empty($datos['maneja_lotes'])) {
                $idsConservados = [];
                foreach ($lotes as $lote) {
                    $payload = [
                        'producto_id' => $id,
                        'sede_id' => (int) $lote['sede_id'],
                        'codigo_lote' => $lote['codigo_lote'],
                        'fecha_elaboracion' => $lote['fecha_elaboracion'] ?? null,
                        'fecha_vencimiento' => $lote['fecha_vencimiento'] ?? null,
                        'cantidad_inicial' => $lote['cantidad_inicial'] ?? 0,
                        'stock_actual' => $lote['stock_actual'] ?? 0,
                        'costo_unitario' => $lote['costo_unitario'] ?? null,
                        'activo' => $lote['activo'] ?? true,
                        'updated_at' => now(),
                    ];

                    if (! empty($lote['id'])) {
                        DB::table('inventario.lotes_producto')
                            ->where('id', $lote['id'])
                            ->where('producto_id', $id)
                            ->update($payload);
                        $this->sincronizarEstadoLote((int) $lote['id']);
                        $idsConservados[] = (int) $lote['id'];
                    } else {
                        $payload['fecha_ingreso_inventario'] = now()->toDateString();
                        $payload['estado_id'] = $this->estadoLoteId($payload['fecha_vencimiento'] ?? null, (float) ($payload['stock_actual'] ?? 0));
                        $payload['created_at'] = now();
                        $idsConservados[] = DB::table('inventario.lotes_producto')->insertGetId($payload);
                    }
                }

                DB::table('inventario.lotes_producto')
                    ->where('producto_id', $id)
                    ->when($idsConservados, fn ($q) => $q->whereNotIn('id', $idsConservados))
                    ->update(['activo' => false, 'updated_at' => now()]);
            } else {
                DB::table('inventario.lotes_producto')
                    ->where('producto_id', $id)
                    ->update(['activo' => false, 'updated_at' => now()]);
            }

            $this->actualizarStockGlobal($id);

            if ($productoAnterior?->imagen_path && $productoAnterior->imagen_path !== ($datos['imagen_path'] ?? null)) {
                Storage::disk('public')->delete($productoAnterior->imagen_path);
            }

            return $this->completarProducto($this->obtenerProducto($id));
        });
    }

    public function listarMovimientos(array $filtros)
    {
        $query = DB::table('inventario.movimientos')
            ->join('inventario.productos', 'inventario.movimientos.producto_id', '=', 'inventario.productos.id')
            ->leftJoin('institucional.sedes', 'inventario.movimientos.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('seguridad.users', 'inventario.movimientos.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('configuracion.estados_catalogo as estado_mov', 'estado_mov.id', '=', 'inventario.movimientos.estado_id')
            ->select('inventario.movimientos.*', 'inventario.productos.codigo as producto_codigo', 'inventario.productos.nombre as producto_nombre', 'institucional.sedes.nombre as sede_nombre', 'seguridad.users.name as usuario_nombre', 'estado_mov.valor_interno as estado_valor', 'estado_mov.nombre as estado_nombre', 'estado_mov.color as estado_color');

        $this->buscar($query, $filtros['busqueda'] ?? null, ['inventario.productos.codigo', 'inventario.productos.nombre', 'inventario.movimientos.tipo_movimiento', 'inventario.movimientos.referencia']);
        $this->filtrarTexto($query, 'inventario.productos.nombre', $filtros['producto'] ?? null);
        $this->filtrarTexto($query, 'inventario.movimientos.tipo_movimiento', $filtros['tipo'] ?? null);
        $this->filtrarTexto($query, 'institucional.sedes.nombre', $filtros['sede'] ?? null);

        return $query->orderByDesc('inventario.movimientos.fecha_movimiento')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarMovimiento(array $datos, ?int $usuarioId = null): object
    {
        return DB::transaction(function () use ($datos, $usuarioId): object {
            $producto = DB::table('inventario.productos')->where('id', $datos['producto_id'])->lockForUpdate()->first();
            if (! $producto) {
                throw new \RuntimeException('Producto no encontrado.');
            }

            $sedeId = $datos['sede_id'] ?? null;
            if ($producto->controla_stock && ! $sedeId) {
                throw new \InvalidArgumentException('La sede es obligatoria para movimientos de productos con control de stock.');
            }

            $cantidad = (float) $datos['cantidad'];
            $tipo = $datos['tipo_movimiento'];

            if ($tipo === 'AJUSTE_INICIAL' && DB::table('inventario.movimientos')
                ->where('producto_id', $producto->id)
                ->where('sede_id', $sedeId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'tipo_movimiento' => 'El inventario inicial solo puede registrarse antes de cualquier otro movimiento del producto en la sede.',
                ]);
            }

            $factor = in_array($tipo, ['SALIDA', 'BAJA'], true) ? -1 : 1;

            $stockSede = DB::table('inventario.producto_stock_sede')
                ->where('producto_id', $producto->id)
                ->where('sede_id', $sedeId)
                ->lockForUpdate()
                ->first();

            if (! $stockSede) {
                DB::table('inventario.producto_stock_sede')->insert([
                    'producto_id' => $producto->id,
                    'sede_id' => $sedeId,
                    'stock_actual' => 0,
                    'stock_minimo' => $producto->stock_minimo ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stockSede = DB::table('inventario.producto_stock_sede')
                    ->where('producto_id', $producto->id)
                    ->where('sede_id', $sedeId)
                    ->lockForUpdate()
                    ->first();
            }

            $stockAnterior = (float) $stockSede->stock_actual;
            $stockNuevo = $stockAnterior + ($cantidad * $factor);
            if ($stockNuevo < 0) {
                throw new \InvalidArgumentException('El movimiento dejaría el stock de la sede en negativo.');
            }

            DB::table('inventario.producto_stock_sede')->where('id', $stockSede->id)->update([
                'stock_actual' => $stockNuevo,
                'updated_at' => now(),
            ]);

            $loteId = $datos['lote_id'] ?? null;
            $asignacionesLote = [];

            if ($producto->maneja_lotes) {
                if (in_array($tipo, ['SALIDA', 'BAJA'], true)) {
                    $pendiente = $cantidad;
                    $estadoDisponibleId = $this->estados->idPorValor('INVENTARIO_LOTE', 'DISPONIBLE');
                    $lotesFefo = DB::table('inventario.lotes_producto')
                        ->where('producto_id', $producto->id)
                        ->where('sede_id', $sedeId)
                        ->where('activo', true)
                        ->where('estado_id', $estadoDisponibleId)
                        ->where('stock_actual', '>', 0)
                        ->where(function ($q): void {
                            $q->whereNull('fecha_vencimiento')->orWhereDate('fecha_vencimiento', '>=', now()->toDateString());
                        })
                        ->orderByRaw('fecha_vencimiento asc nulls last')
                        ->orderBy('fecha_ingreso_inventario')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($lotesFefo as $lote) {
                        if ($pendiente <= 0) {
                            break;
                        }

                        $disponible = (float) $lote->stock_actual;
                        $tomar = min($disponible, $pendiente);
                        if ($tomar <= 0) {
                            continue;
                        }

                        DB::table('inventario.lotes_producto')->where('id', $lote->id)->update([
                            'stock_actual' => $disponible - $tomar,
                            'updated_at' => now(),
                        ]);
                        $this->sincronizarEstadoLote((int) $lote->id);

                        $asignacionesLote[] = ['lote_id' => (int) $lote->id, 'cantidad' => $tomar];
                        $pendiente -= $tomar;
                    }

                    if ($pendiente > 0.00001) {
                        throw new \InvalidArgumentException('No existe stock suficiente en los lotes activos de la sede.');
                    }

                    $loteId = $asignacionesLote[0]['lote_id'] ?? null;
                } else {
                    if (! $loteId) {
                        throw new \InvalidArgumentException('Debe seleccionar un lote para una entrada o ajuste de un producto que maneja lotes.');
                    }

                    $lote = DB::table('inventario.lotes_producto')
                        ->where('id', $loteId)
                        ->where('producto_id', $producto->id)
                        ->where('sede_id', $sedeId)
                        ->where('activo', true)
                        ->lockForUpdate()
                        ->first();

                    if (! $lote) {
                        throw new \InvalidArgumentException('El lote seleccionado no corresponde al producto y sede.');
                    }

                    DB::table('inventario.lotes_producto')->where('id', $lote->id)->update([
                        'stock_actual' => (float) $lote->stock_actual + $cantidad,
                        'updated_at' => now(),
                    ]);
                    $this->sincronizarEstadoLote((int) $lote->id);

                    $asignacionesLote[] = ['lote_id' => (int) $lote->id, 'cantidad' => $cantidad];
                }
            }

            $movimientoId = DB::table('inventario.movimientos')->insertGetId([
                'producto_id' => $producto->id,
                'lote_id' => $loteId,
                'venta_id' => $datos['venta_id'] ?? null,
                'sede_id' => $sedeId,
                'usuario_id' => $usuarioId,
                'tipo_movimiento' => $tipo,
                'estado_id' => $this->estados->idPorValor('INVENTARIO_MOVIMIENTO', 'REGISTRADO'),
                'cantidad' => $cantidad,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'referencia' => $datos['referencia'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'fecha_movimiento' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($asignacionesLote as $asignacion) {
                DB::table('inventario.movimiento_lotes')->insert([
                    'movimiento_id' => $movimientoId,
                    'lote_id' => $asignacion['lote_id'],
                    'cantidad' => $asignacion['cantidad'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->actualizarStockGlobal((int) $producto->id);

            $movimiento = $this->obtenerMovimiento($movimientoId);
            $this->auditar('inventario', 'CREAR', 'inventario.movimientos', $movimientoId, null, $movimiento, "Movimiento {$tipo} de {$cantidad} sobre {$producto->nombre} en sede {$sedeId}.");

            return $movimiento;
        });
    }

    public function registrarSalidaVenta(int $productoId, int $sedeId, float $cantidad, int $ventaId, ?int $usuarioId = null): object
    {
        $existente = DB::table('inventario.movimientos')
            ->where('venta_id', $ventaId)
            ->where('producto_id', $productoId)
            ->where('tipo_movimiento', 'SALIDA')
            ->first();

        if ($existente) {
            return $this->obtenerMovimiento((int) $existente->id);
        }

        return $this->guardarMovimiento([
            'producto_id' => $productoId,
            'venta_id' => $ventaId,
            'sede_id' => $sedeId,
            'tipo_movimiento' => 'SALIDA',
            'cantidad' => $cantidad,
            'referencia' => 'VENTA-' . $ventaId,
            'observaciones' => 'Salida automática por venta pagada #' . $ventaId . '.',
        ], $usuarioId);
    }

    public function catalogos(): array
    {
        return [
            'categorias' => DB::table('inventario.categorias_producto')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => DB::table('inventario.proveedores')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'productos' => DB::table('inventario.productos')->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'stock_actual', 'maneja_lotes']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->where('maneja_inventario', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
            'lotes' => DB::table('inventario.lotes_producto as l')->join('inventario.productos as p', 'p.id', '=', 'l.producto_id')->join('institucional.sedes as s', 's.id_sede', '=', 'l.sede_id')->leftJoin('configuracion.estados_catalogo as e', 'e.id', '=', 'l.estado_id')->where('l.activo', true)->orderByRaw('l.fecha_vencimiento asc nulls last')->get(['l.id', 'l.producto_id', 'l.sede_id', 'l.codigo_lote', 'l.fecha_vencimiento', 'l.stock_actual', 'l.estado_id', 'e.valor_interno as estado_valor', 'e.nombre as estado_nombre', 'e.color as estado_color', 'p.nombre as producto_nombre', 's.nombre as sede_nombre']),
        ];
    }

    public function opcionesFiltro(): array
    {
        return [
            'nombre' => DB::table('inventario.categorias_producto')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'proveedor' => DB::table('inventario.proveedores')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'producto' => DB::table('inventario.productos')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'categoria' => DB::table('inventario.categorias_producto')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
            'sede' => DB::table('institucional.sedes')->distinct()->orderBy('nombre')->pluck('nombre')->values(),
        ];
    }

    private function listarSimple(string $tabla, array $filtros, array $columnas)
    {
        $query = DB::table($tabla);
        $this->buscar($query, $filtros['busqueda'] ?? null, $columnas);
        $this->filtrarTexto($query, 'nombre', $filtros['nombre'] ?? null);
        $this->filtrarBooleano($query, 'activo', $filtros['estado'] ?? null);
        return $query->orderBy('nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    private function guardar(string $tabla, array $datos, ?int $id): object
    {
        return DB::table($tabla)->where('id', $this->guardarRetornandoId($tabla, $datos, $id))->first();
    }

    private function guardarRetornandoId(string $tabla, array $datos, ?int $id): int
    {
        $antes = $id ? DB::table($tabla)->where('id', $id)->first() : null;
        $datos['updated_at'] = now();
        if ($id) {
            DB::table($tabla)->where('id', $id)->update($datos);
            $this->auditar('inventario', 'ACTUALIZAR', $tabla, $id, $antes, DB::table($tabla)->where('id', $id)->first());
            return $id;
        }
        $datos['created_at'] = now();
        $nuevoId = DB::table($tabla)->insertGetId($datos);
        $this->auditar('inventario', 'CREAR', $tabla, $nuevoId, null, DB::table($tabla)->where('id', $nuevoId)->first());
        return $nuevoId;
    }

    private function obtenerProducto(int $id): object
    {
        return DB::table('inventario.productos')
            ->leftJoin('inventario.categorias_producto', 'inventario.productos.categoria_id', '=', 'inventario.categorias_producto.id')
            ->leftJoin('inventario.proveedores', 'inventario.productos.proveedor_id', '=', 'inventario.proveedores.id')
            ->select('inventario.productos.*', 'inventario.categorias_producto.nombre as categoria_nombre', 'inventario.proveedores.nombre as proveedor_nombre')
            ->where('inventario.productos.id', $id)->first();
    }

    private function completarProducto(object $producto): object
    {
        $this->sincronizarEstadosLotesProducto((int) $producto->id);

        $producto->precios_sede = DB::table('inventario.producto_precios_sede as ps')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'ps.sede_id')
            ->where('ps.producto_id', $producto->id)
            ->orderBy('s.nombre')
            ->get(['ps.sede_id', 's.nombre as sede_nombre', 'ps.precio', 'ps.activo']);

        $producto->stocks_sede = DB::table('inventario.producto_stock_sede as ss')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'ss.sede_id')
            ->where('ss.producto_id', $producto->id)
            ->orderBy('s.nombre')
            ->get(['ss.sede_id', 's.nombre as sede_nombre', 'ss.stock_actual', 'ss.stock_minimo'])
            ->map(function ($stock) use ($producto) {
                $stock->stock_inicial_editable = ! DB::table('inventario.movimientos')
                    ->where('producto_id', $producto->id)
                    ->where('sede_id', $stock->sede_id)
                    ->where('tipo_movimiento', '<>', 'AJUSTE_INICIAL')
                    ->exists();
                return $stock;
            });

        $producto->lotes = DB::table('inventario.lotes_producto as l')
            ->join('institucional.sedes as s', 's.id_sede', '=', 'l.sede_id')
            ->leftJoin('configuracion.estados_catalogo as e', 'e.id', '=', 'l.estado_id')
            ->where('l.producto_id', $producto->id)
            ->where('l.activo', true)
            ->orderByRaw('l.fecha_vencimiento asc nulls last')
            ->get([
                'l.id',
                'l.sede_id',
                's.nombre as sede_nombre',
                'l.codigo_lote',
                'l.fecha_elaboracion',
                'l.fecha_ingreso_inventario',
                'l.fecha_vencimiento',
                'l.cantidad_inicial',
                'l.stock_actual',
                'l.costo_unitario',
                'l.activo',
                'l.estado_id',
                'e.valor_interno as estado_valor',
                'e.nombre as estado_nombre',
                'e.color as estado_color',
            ]);

        return $producto;
    }

    private function actualizarStockGlobal(int $productoId): void
    {
        $total = DB::table('inventario.producto_stock_sede')
            ->where('producto_id', $productoId)
            ->sum('stock_actual');

        DB::table('inventario.productos')->where('id', $productoId)->update([
            'stock_actual' => $total,
            'updated_at' => now(),
        ]);
    }

    private function obtenerMovimiento(int $id): object
    {
        return DB::table('inventario.movimientos')
            ->join('inventario.productos', 'inventario.movimientos.producto_id', '=', 'inventario.productos.id')
            ->leftJoin('institucional.sedes', 'inventario.movimientos.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('seguridad.users', 'inventario.movimientos.usuario_id', '=', 'seguridad.users.id')
            ->leftJoin('configuracion.estados_catalogo as estado_mov', 'estado_mov.id', '=', 'inventario.movimientos.estado_id')
            ->select('inventario.movimientos.*', 'inventario.productos.codigo as producto_codigo', 'inventario.productos.nombre as producto_nombre', 'institucional.sedes.nombre as sede_nombre', 'seguridad.users.name as usuario_nombre', 'estado_mov.valor_interno as estado_valor', 'estado_mov.nombre as estado_nombre', 'estado_mov.color as estado_color')
            ->where('inventario.movimientos.id', $id)->first();
    }

    private function sincronizarStockInicialSede(int $productoId, int $sedeId, float $stockDeseado, float $stockMinimo): void
    {
        $stockDeseado = round(max(0, $stockDeseado), 2);

        $stock = DB::table('inventario.producto_stock_sede')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            DB::table('inventario.producto_stock_sede')->insert([
                'producto_id' => $productoId,
                'sede_id' => $sedeId,
                'stock_actual' => 0,
                'stock_minimo' => $stockMinimo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stock = DB::table('inventario.producto_stock_sede')
                ->where('producto_id', $productoId)
                ->where('sede_id', $sedeId)
                ->lockForUpdate()
                ->first();
        }

        $tienePosteriores = DB::table('inventario.movimientos')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('tipo_movimiento', '<>', 'AJUSTE_INICIAL')
            ->exists();

        if ($tienePosteriores) {
            if (abs((float) $stock->stock_actual - $stockDeseado) > 0.00001) {
                throw ValidationException::withMessages([
                    'stocks_sede' => 'El stock actual ya tiene movimientos de Kardex y no puede editarse directamente. Registra un AJUSTE de inventario.',
                ]);
            }

            DB::table('inventario.producto_stock_sede')->where('id', $stock->id)->update([
                'stock_minimo' => $stockMinimo,
                'updated_at' => now(),
            ]);
            return;
        }

        $inicial = DB::table('inventario.movimientos')
            ->where('producto_id', $productoId)
            ->where('sede_id', $sedeId)
            ->where('tipo_movimiento', 'AJUSTE_INICIAL')
            ->orderBy('id')
            ->first();

        DB::table('inventario.producto_stock_sede')->where('id', $stock->id)->update([
            'stock_actual' => $stockDeseado,
            'stock_minimo' => $stockMinimo,
            'updated_at' => now(),
        ]);

        if ($inicial) {
            DB::table('inventario.movimientos')->where('id', $inicial->id)->update([
                'cantidad' => $stockDeseado,
                'stock_anterior' => 0,
                'stock_nuevo' => $stockDeseado,
                'observaciones' => 'Inventario inicial corregido antes de movimientos posteriores.',
                'updated_at' => now(),
            ]);
            return;
        }

        if ($stockDeseado <= 0) {
            return;
        }

        DB::table('inventario.movimientos')->insert([
            'producto_id' => $productoId,
            'sede_id' => $sedeId,
            'usuario_id' => null,
            'tipo_movimiento' => 'AJUSTE_INICIAL',
            'estado_id' => $this->estados->idPorValor('INVENTARIO_MOVIMIENTO', 'REGISTRADO'),
            'cantidad' => $stockDeseado,
            'stock_anterior' => 0,
            'stock_nuevo' => $stockDeseado,
            'referencia' => 'INVENTARIO-INICIAL',
            'observaciones' => 'Carga inicial de inventario.',
            'fecha_movimiento' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function estadoLoteId(?string $fechaVencimiento, float $stockActual): int
    {
        if ($fechaVencimiento && $fechaVencimiento < now()->toDateString()) {
            return $this->estados->idPorValor('INVENTARIO_LOTE', 'VENCIDO');
        }

        if ($stockActual <= 0) {
            return $this->estados->idPorValor('INVENTARIO_LOTE', 'AGOTADO');
        }

        return $this->estados->idPorValor('INVENTARIO_LOTE', 'DISPONIBLE');
    }

    private function sincronizarEstadoLote(int $loteId): void
    {
        $lote = DB::table('inventario.lotes_producto')->where('id', $loteId)->first();
        if (! $lote) {
            return;
        }

        $estadoBloqueado = $this->estados->porValor('INVENTARIO_LOTE', 'BLOQUEADO');
        if ($estadoBloqueado && (int) $lote->estado_id === (int) $estadoBloqueado->id) {
            return;
        }

        $estadoId = $this->estadoLoteId($lote->fecha_vencimiento, (float) $lote->stock_actual);
        if ((int) $lote->estado_id !== $estadoId) {
            DB::table('inventario.lotes_producto')->where('id', $loteId)->update([
                'estado_id' => $estadoId,
                'updated_at' => now(),
            ]);
        }
    }

    private function sincronizarEstadosLotesProducto(int $productoId): void
    {
        DB::table('inventario.lotes_producto')
            ->where('producto_id', $productoId)
            ->where('activo', true)
            ->pluck('id')
            ->each(fn ($id) => $this->sincronizarEstadoLote((int) $id));
    }

    private function buscar($query, ?string $busqueda, array $columnas): void
    {
        if (empty($busqueda)) return;
        $texto = mb_strtolower($busqueda);
        $query->where(function ($q) use ($columnas, $texto): void {
            foreach ($columnas as $columna) $q->orWhereRaw("LOWER({$columna}) LIKE ?", ["%{$texto}%"]);
        });
    }

    private function filtrarTexto($query, string $columna, mixed $valor): void
    {
        if (empty($valor)) return;
        is_array($valor) ? $query->whereIn($columna, array_filter($valor)) : $query->whereRaw("LOWER({$columna}) LIKE ?", ['%' . mb_strtolower($valor) . '%']);
    }

    private function filtrarBooleano($query, string $columna, mixed $valor): void
    {
        if ($valor === null || $valor === '') return;
        $booleanos = collect(is_array($valor) ? $valor : [$valor])->map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))->filter(fn ($item) => $item !== null)->values();
        if ($booleanos->isNotEmpty()) $query->whereIn($columna, $booleanos->all());
    }
}
