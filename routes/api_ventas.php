<?php

use App\Http\Controllers\Ventas\VentaController;
use App\Http\Controllers\Ventas\PuntoVentaController;
use App\Http\Controllers\Ventas\DevolucionVentaController;
use Illuminate\Support\Facades\Route;

Route::get('ventas/cierre-caja', [VentaController::class, 'cierreCaja']);
Route::get('ventas', [VentaController::class, 'index']);
Route::get('ventas/devoluciones', [DevolucionVentaController::class, 'index']);
Route::get('ventas/devoluciones/buscar', [DevolucionVentaController::class, 'buscar']);
Route::post('ventas/devoluciones', [DevolucionVentaController::class, 'store']);
Route::get('ventas/punto-venta/catalogo', [PuntoVentaController::class, 'catalogo']);
Route::get('ventas/punto-venta/abiertas', [PuntoVentaController::class, 'abiertas']);
Route::get('ventas/punto-venta/borradores', [PuntoVentaController::class, 'borradores']);
Route::post('ventas/punto-venta/borradores', [PuntoVentaController::class, 'guardarBorrador']);
Route::post('ventas/punto-venta/borradores/{id}/confirmar', [PuntoVentaController::class, 'confirmarBorrador']);
Route::delete('ventas/punto-venta/borradores/{id}', [PuntoVentaController::class, 'eliminarBorrador']);
Route::post('ventas/punto-venta', [PuntoVentaController::class, 'store']);
