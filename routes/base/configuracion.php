<?php

use App\Http\Controllers\Api\Configuracion\EstadoCatalogoControlador;
use App\Http\Controllers\Api\Configuracion\InstitucionFinancieraControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('configuracion')->group(function (): void {
    Route::get('estados', [EstadoCatalogoControlador::class, 'index'])->name('configuracion.estados.index');

    Route::middleware(['base.permiso:CONFIGURACION-ESTADOS'])->group(function (): void {
        Route::post('estados', [EstadoCatalogoControlador::class, 'store'])->name('configuracion.estados.store');
        Route::put('estados/{id}', [EstadoCatalogoControlador::class, 'update'])->name('configuracion.estados.update');
        Route::patch('estados/{id}/desactivar', [EstadoCatalogoControlador::class, 'desactivar'])->name('configuracion.estados.desactivar');
    });

    // Lo consulta cualquier usuario que cobra; solo se administra con su permiso.
    Route::get('instituciones-financieras', [InstitucionFinancieraControlador::class, 'index'])->name('configuracion.instituciones-financieras.index');
    Route::middleware(['base.permiso:CONFIGURACION-INSTITUCIONES-FINANCIERAS'])->group(function (): void {
        Route::post('instituciones-financieras', [InstitucionFinancieraControlador::class, 'store'])->name('configuracion.instituciones-financieras.store');
        Route::put('instituciones-financieras/{id}', [InstitucionFinancieraControlador::class, 'update'])->whereNumber('id')->name('configuracion.instituciones-financieras.update');
        Route::patch('instituciones-financieras/{id}/estado', [InstitucionFinancieraControlador::class, 'estado'])->whereNumber('id')->name('configuracion.instituciones-financieras.estado');
    });
});
