<?php

use App\Http\Controllers\Inventarios\CategoriaProductoController;
use App\Http\Controllers\Inventarios\InventarioStockController;
use App\Http\Controllers\Inventarios\ProductoMovimientoController;
use App\Http\Controllers\Inventarios\ProductoPrecioController;
use App\Http\Controllers\Inventarios\ProductoController;
use App\Http\Controllers\Inventarios\ProveedorController;
use Illuminate\Support\Facades\Route;

// Productos
Route::get('productos', [ProductoController::class, 'index']);
Route::get('productos/{id}', [ProductoController::class, 'show']);
Route::post('productos', [ProductoController::class, 'store']);
Route::put('productos/{id}', [ProductoController::class, 'update']);
Route::delete('productos/{id}', [ProductoController::class, 'destroy']);
Route::get('sedes', [ProductoController::class, 'sedes']);

// Especializados
Route::get('productos/{id}/stock', [InventarioStockController::class, 'index']);
Route::delete('productos/{id}/stock/{stockId}', [InventarioStockController::class, 'destroy']);
Route::post('productos/{id}/inventario-inicial', [ProductoMovimientoController::class, 'inventarioInicial']);

// Precios
Route::get('productos/{id}/precios', [ProductoPrecioController::class, 'index']);
Route::post('productos/{id}/precios', [ProductoPrecioController::class, 'store']);
Route::put('producto-precios/{id}', [ProductoPrecioController::class, 'update']);
Route::delete('producto-precios/{id}', [ProductoPrecioController::class, 'destroy']);

// Movimientos y Ajustes
Route::get('movimientos', [ProductoMovimientoController::class, 'index']);
Route::post('movimientos/entrada', [ProductoMovimientoController::class, 'entrada']);
Route::post('movimientos/salida', [ProductoMovimientoController::class, 'salida']);
Route::post('movimientos/ajuste', [ProductoMovimientoController::class, 'ajuste']);
Route::post('movimientos/baja', [ProductoMovimientoController::class, 'baja']);
Route::post('movimientos/transferencia', [ProductoMovimientoController::class, 'transferencia']);

// Otros
Route::get('categorias-producto', [CategoriaProductoController::class, 'index']);

// Proveedores
Route::get('proveedores', [ProveedorController::class, 'index']);
Route::get('proveedores/{id}', [ProveedorController::class, 'show']);
Route::post('proveedores', [ProveedorController::class, 'store']);
Route::put('proveedores/{id}', [ProveedorController::class, 'update']);
Route::delete('proveedores/{id}', [ProveedorController::class, 'destroy']);
