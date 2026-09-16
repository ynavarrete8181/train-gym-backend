<?php

use App\Http\Controllers\Api\Configuracion\EstadoCatalogoControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('configuracion')->group(function (): void {
    Route::middleware(['base.permiso:CONFIGURACION-ESTADOS'])->group(function (): void {
        Route::get('estados', [EstadoCatalogoControlador::class, 'index'])->name('configuracion.estados.index');
        Route::post('estados', [EstadoCatalogoControlador::class, 'store'])->name('configuracion.estados.store');
        Route::put('estados/{id}', [EstadoCatalogoControlador::class, 'update'])->name('configuracion.estados.update');
        Route::patch('estados/{id}/desactivar', [EstadoCatalogoControlador::class, 'desactivar'])->name('configuracion.estados.desactivar');
    });
});
