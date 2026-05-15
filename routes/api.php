<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\Horarios\HorarioGymController;
use App\Http\Controllers\Inventarios\CategoriaProductoController;
use App\Http\Controllers\Inventarios\InventarioStockController;
use App\Http\Controllers\Inventarios\ProductoMovimientoController;
use App\Http\Controllers\Inventarios\ProductoPrecioController;
use App\Http\Controllers\Inventarios\ProductoController;
use App\Http\Controllers\Servicios\CategoriaServicioController;
use App\Http\Controllers\Servicios\ServicioController;
use App\Http\Controllers\Ventas\PuntoVentaController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->post('auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('auth/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->get('auth/me', [AuthController::class, 'me']);

Route::middleware('auth:sanctum')->prefix('gimnasio')->group(function () {
    Route::get('auditoria', [AuditController::class, 'index']);
    Route::get('auditoria/resumen', [AuditController::class, 'summary']);

    Route::get('horarios', [HorarioGymController::class, 'index']);
    Route::get('horarios/{id}', [HorarioGymController::class, 'show']);
    Route::post('horarios', [HorarioGymController::class, 'store']);
    Route::put('horarios/{id}', [HorarioGymController::class, 'update']);
    Route::delete('horarios/{id}', [HorarioGymController::class, 'destroy']);

    Route::get('categoria-servicio', [CategoriaServicioController::class, 'index']);
    Route::get('categoria-servicio/{id}', [CategoriaServicioController::class, 'show']);
    Route::post('categoria-servicio', [CategoriaServicioController::class, 'store']);
    Route::put('categoria-servicio/{id}', [CategoriaServicioController::class, 'update']);
    Route::delete('categoria-servicio/{id}', [CategoriaServicioController::class, 'destroy']);
    Route::get('categoria-servicio/{idCategoria}/servicios', [CategoriaServicioController::class, 'serviciosByCategoria']);

    Route::get('servicios', [ServicioController::class, 'index']);
    Route::get('servicios/{id}', [ServicioController::class, 'show']);
    Route::post('servicios', [ServicioController::class, 'store']);
    Route::put('servicios/{id}', [ServicioController::class, 'update']);
    Route::delete('servicios/{id}', [ServicioController::class, 'destroy']);

    Route::post('ventas/punto-venta', [PuntoVentaController::class, 'store']);
});

// INVENTARIO (ESTILO DBANU - FUERA DE GIMNASIO)
Route::middleware('auth:sanctum')->prefix('inventario')->group(function () {
    // Productos
    Route::get('productos', [ProductoController::class, 'index']);
    Route::get('productos/{id}', [ProductoController::class, 'show']);
    Route::post('productos', [ProductoController::class, 'store']);
    Route::put('productos/{id}', [ProductoController::class, 'update']);
    Route::delete('productos/{id}', [ProductoController::class, 'destroy']);
    Route::get('sedes', [ProductoController::class, 'sedes']);
    
    // Especializados
    Route::get('productos/{id}/stock', [InventarioStockController::class, 'index']);
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

    // Otros
    Route::get('categorias-producto', [CategoriaProductoController::class, 'index']);
});
