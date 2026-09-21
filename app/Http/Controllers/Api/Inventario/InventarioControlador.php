<?php

namespace App\Http\Controllers\Api\Inventario;

use App\Http\Controllers\Controller;
use App\Services\Inventario\InventarioServicio;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class InventarioControlador extends Controller
{
    public function __construct(private readonly InventarioServicio $inventario)
    {
    }

    public function categorias(Request $request) { return $this->respuesta('Categorías consultadas.', $this->inventario->listarCategorias($request->all())); }

    public function guardarCategoria(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('inventario.categorias_producto', 'nombre')->ignore($id)],
            'descripcion' => 'nullable|string',
            'activo' => 'boolean',
        ]);
        return ApiResponse::exito('Categoría guardada correctamente.', (array) $this->inventario->guardarCategoria($datos, $id), [], $id ? 200 : 201);
    }

    public function proveedores(Request $request) { return $this->respuesta('Proveedores consultados.', $this->inventario->listarProveedores($request->all())); }

    public function guardarProveedor(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'ruc' => ['nullable', 'string', 'max:20', Rule::unique('inventario.proveedores', 'ruc')->ignore($id)],
            'nombre' => 'required|string|max:160',
            'telefono' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'direccion' => 'nullable|string',
            'activo' => 'boolean',
        ]);
        return ApiResponse::exito('Proveedor guardado correctamente.', (array) $this->inventario->guardarProveedor($datos, $id), [], $id ? 200 : 201);
    }

    public function productos(Request $request) { return $this->respuesta('Productos consultados.', $this->inventario->listarProductos($request->all())); }

    public function subirImagenProducto(Request $request)
    {
        $request->validate([
            'imagen' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $path = $request->file('imagen')->store('productos', 'public');

        return ApiResponse::exito('Imagen de producto cargada correctamente.', [
            'imagen_path' => $path,
            'imagen_url' => url(Storage::url($path)),
        ]);
    }


    public function guardarProducto(Request $request, ?int $id = null)
    {
        $datos = $request->validate([
            'categoria_id' => 'nullable|exists:pgsql.inventario.categorias_producto,id',
            'proveedor_id' => 'nullable|exists:pgsql.inventario.proveedores,id',
            'codigo' => ['required', 'string', 'max:60', Rule::unique('inventario.productos', 'codigo')->ignore($id)],
            'nombre' => 'required|string|max:160',
            'descripcion' => 'nullable|string',
            'marca' => 'nullable|string|max:100',
            'imagen_url' => 'nullable|string|max:2000',
            'imagen_path' => 'nullable|string|max:500',
            'maneja_lotes' => 'boolean',
            'unidad_medida' => 'required|string|max:30',
            'precio_costo' => 'required|numeric|min:0',
            'precio_venta' => 'required|numeric|min:0',
            'stock_actual' => 'required|numeric|min:0',
            'stock_minimo' => 'required|numeric|min:0',
            'controla_stock' => 'boolean',
            'activo' => 'boolean',
            'precios_sede' => 'nullable|array',
            'precios_sede.*.sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'precios_sede.*.precio' => 'required|numeric|min:0',
            'precios_sede.*.activo' => 'boolean',
            'stocks_sede' => 'nullable|array',
            'stocks_sede.*.sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'stocks_sede.*.stock_actual' => 'required|numeric|min:0',
            'stocks_sede.*.stock_minimo' => 'required|numeric|min:0',
            'lotes' => 'nullable|array',
            'lotes.*.id' => 'nullable|integer',
            'lotes.*.sede_id' => 'required|exists:pgsql.institucional.sedes,id_sede',
            'lotes.*.codigo_lote' => 'required|string|max:80',
            'lotes.*.fecha_elaboracion' => 'nullable|date',
            'lotes.*.fecha_ingreso_inventario' => 'nullable|date',
            'lotes.*.fecha_vencimiento' => 'nullable|date',
            'lotes.*.cantidad_inicial' => 'required|numeric|min:0',
            'lotes.*.stock_actual' => 'required|numeric|min:0',
            'lotes.*.costo_unitario' => 'nullable|numeric|min:0',
            'lotes.*.activo' => 'boolean',
        ]);
        return ApiResponse::exito('Producto guardado correctamente.', (array) $this->inventario->guardarProducto($datos, $id), [], $id ? 200 : 201);
    }

    public function movimientos(Request $request) { return $this->respuesta('Movimientos consultados.', $this->inventario->listarMovimientos($request->all())); }

    public function guardarMovimiento(Request $request)
    {
        $datos = $request->validate([
            'producto_id' => 'required|exists:pgsql.inventario.productos,id',
            'lote_id' => 'nullable|exists:pgsql.inventario.lotes_producto,id',
            'sede_id' => 'nullable|exists:pgsql.institucional.sedes,id_sede',
            'tipo_movimiento' => 'required|string|in:ENTRADA,SALIDA,AJUSTE,BAJA',
            'cantidad' => 'required|numeric|min:0.01',
            'referencia' => 'nullable|string|max:120',
            'observaciones' => 'nullable|string',
        ]);
        return ApiResponse::exito('Movimiento registrado correctamente.', (array) $this->inventario->guardarMovimiento($datos, $request->user()?->id), [], 201);
    }

    private function respuesta(string $mensaje, $paginador)
    {
        return ApiResponse::exito($mensaje, $paginador->items(), [
            'pagina_actual' => $paginador->currentPage(),
            'por_pagina' => $paginador->perPage(),
            'total' => $paginador->total(),
            'ultima_pagina' => $paginador->lastPage(),
            'opciones_filtro' => $this->inventario->opcionesFiltro(),
            'catalogos' => $this->inventario->catalogos(),
        ]);
    }
}
