<?php

use App\Http\Controllers\Api\Inventario\InventarioControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('inventario')->group(function (): void {
    Route::middleware(['base.permiso:INVENTARIO-CATEGORIAS'])->group(function (): void {
        Route::get('categorias', [InventarioControlador::class, 'categorias'])->name('inventario.categorias.index');
        Route::post('categorias', [InventarioControlador::class, 'guardarCategoria'])->name('inventario.categorias.store');
        Route::put('categorias/{id}', [InventarioControlador::class, 'guardarCategoria'])->name('inventario.categorias.update');
    });

    Route::middleware(['base.permiso:INVENTARIO-PROVEEDORES'])->group(function (): void {
        Route::get('proveedores', [InventarioControlador::class, 'proveedores'])->name('inventario.proveedores.index');
        Route::post('proveedores', [InventarioControlador::class, 'guardarProveedor'])->name('inventario.proveedores.store');
        Route::put('proveedores/{id}', [InventarioControlador::class, 'guardarProveedor'])->name('inventario.proveedores.update');
    });

    Route::middleware(['base.permiso:INVENTARIO-PRODUCTOS'])->group(function (): void {
        Route::get('productos', [InventarioControlador::class, 'productos'])->name('inventario.productos.index');
        Route::post('productos', [InventarioControlador::class, 'guardarProducto'])->name('inventario.productos.store');
        Route::post('productos/imagen', [InventarioControlador::class, 'subirImagenProducto'])->name('inventario.productos.imagen');
        Route::put('productos/{id}', [InventarioControlador::class, 'guardarProducto'])->name('inventario.productos.update');
    });

    Route::middleware(['base.permiso:INVENTARIO-MOVIMIENTOS'])->group(function (): void {
        Route::get('movimientos', [InventarioControlador::class, 'movimientos'])->name('inventario.movimientos.index');
        Route::post('movimientos', [InventarioControlador::class, 'guardarMovimiento'])->name('inventario.movimientos.store');
    });
});
