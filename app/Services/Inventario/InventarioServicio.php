<?php

namespace App\Services\Inventario;

use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Facades\DB;

class InventarioServicio
{
    use RegistraAuditoria;

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

        return $query->orderBy('inventario.productos.nombre')->paginate($filtros['per_page'] ?? 5, ['*'], 'page', $filtros['page'] ?? 1);
    }

    public function guardarProducto(array $datos, ?int $id = null): object
    {
        $id = $this->guardarRetornandoId('inventario.productos', $datos, $id);
        return $this->obtenerProducto($id);
    }

    public function listarMovimientos(array $filtros)
    {
        $query = DB::table('inventario.movimientos')
            ->join('inventario.productos', 'inventario.movimientos.producto_id', '=', 'inventario.productos.id')
            ->leftJoin('institucional.sedes', 'inventario.movimientos.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('seguridad.users', 'inventario.movimientos.usuario_id', '=', 'seguridad.users.id')
            ->select('inventario.movimientos.*', 'inventario.productos.codigo as producto_codigo', 'inventario.productos.nombre as producto_nombre', 'institucional.sedes.nombre as sede_nombre', 'seguridad.users.name as usuario_nombre');

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
            $cantidad = (float) $datos['cantidad'];
            $tipo = $datos['tipo_movimiento'];
            $stockAnterior = (float) $producto->stock_actual;
            $factor = in_array($tipo, ['SALIDA', 'BAJA'], true) ? -1 : 1;
            $stockNuevo = max(0, $stockAnterior + ($cantidad * $factor));

            DB::table('inventario.productos')->where('id', $producto->id)->update(['stock_actual' => $stockNuevo, 'updated_at' => now()]);

            $movimientoId = DB::table('inventario.movimientos')->insertGetId([
                'producto_id' => $producto->id,
                'sede_id' => $datos['sede_id'] ?? null,
                'usuario_id' => $usuarioId,
                'tipo_movimiento' => $tipo,
                'cantidad' => $cantidad,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'referencia' => $datos['referencia'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'fecha_movimiento' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $movimiento = $this->obtenerMovimiento($movimientoId);
            $this->auditar('inventario', 'CREAR', 'inventario.movimientos', $movimientoId, null, $movimiento, "Movimiento {$tipo} de {$cantidad} sobre {$producto->nombre}.");

            return $movimiento;
        });
    }

    public function catalogos(): array
    {
        return [
            'categorias' => DB::table('inventario.categorias_producto')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => DB::table('inventario.proveedores')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'productos' => DB::table('inventario.productos')->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'stock_actual']),
            'sedes' => DB::table('institucional.sedes')->where('activo', true)->orderBy('nombre')->get(['id_sede as id', 'nombre']),
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

    private function obtenerMovimiento(int $id): object
    {
        return DB::table('inventario.movimientos')
            ->join('inventario.productos', 'inventario.movimientos.producto_id', '=', 'inventario.productos.id')
            ->leftJoin('institucional.sedes', 'inventario.movimientos.sede_id', '=', 'institucional.sedes.id_sede')
            ->leftJoin('seguridad.users', 'inventario.movimientos.usuario_id', '=', 'seguridad.users.id')
            ->select('inventario.movimientos.*', 'inventario.productos.codigo as producto_codigo', 'inventario.productos.nombre as producto_nombre', 'institucional.sedes.nombre as sede_nombre', 'seguridad.users.name as usuario_nombre')
            ->where('inventario.movimientos.id', $id)->first();
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
