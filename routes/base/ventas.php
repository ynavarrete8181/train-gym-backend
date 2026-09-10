<?php

use App\Http\Controllers\Api\Ventas\VentaControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('ventas')->group(function (): void {
    Route::middleware(['base.permiso:VENTAS-CAJAS'])->group(function (): void {
        Route::get('cajas', [VentaControlador::class, 'cajas'])->name('ventas.cajas.index');
        Route::post('cajas', [VentaControlador::class, 'guardarCaja'])->name('ventas.cajas.store');
        Route::put('cajas/{id}', [VentaControlador::class, 'guardarCaja'])->name('ventas.cajas.update');
    });

    Route::middleware(['base.permiso:VENTAS-VENTAS'])->group(function (): void {
        Route::get('ventas', [VentaControlador::class, 'ventas'])->name('ventas.ventas.index');
        Route::post('ventas', [VentaControlador::class, 'guardarVenta'])->name('ventas.ventas.store');
        Route::put('ventas/{id}', [VentaControlador::class, 'guardarVenta'])->name('ventas.ventas.update');
    });

    Route::middleware(['base.permiso:VENTAS-PAGOS'])->group(function (): void {
        Route::get('pagos', [VentaControlador::class, 'pagos'])->name('ventas.pagos.index');
        Route::post('pagos', [VentaControlador::class, 'guardarPago'])->name('ventas.pagos.store');
    });

    Route::middleware(['base.permiso:VENTAS-COMPROBANTES'])->group(function (): void {
        Route::get('comprobantes', [VentaControlador::class, 'comprobantes'])->name('ventas.comprobantes.index');
    });
});
