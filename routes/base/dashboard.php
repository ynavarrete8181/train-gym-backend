<?php

use App\Http\Controllers\Api\Dashboard\DashboardEjecutivoControlador;
use App\Http\Controllers\Api\Metas\MetaComercialControlador;
use App\Http\Controllers\Api\Alertas\AlertaOperativaControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])
    ->prefix('dashboard')
    ->group(function (): void {
        Route::get('ejecutivo', DashboardEjecutivoControlador::class)
            ->middleware('base.permiso:DASHBOARD-EJECUTIVO')
            ->name('dashboard.ejecutivo');
        Route::get('alertas', [AlertaOperativaControlador::class, 'index'])
            ->middleware('base.permiso:DASHBOARD-ALERTAS')
            ->name('dashboard.alertas');
        Route::post('alertas/procesar', [AlertaOperativaControlador::class, 'procesar'])
            ->middleware('base.permiso:DASHBOARD-ALERTAS')
            ->name('dashboard.alertas.procesar');
        Route::get('alertas/excel', [AlertaOperativaControlador::class, 'excel'])
            ->middleware('base.permiso:DASHBOARD-ALERTAS')
            ->name('dashboard.alertas.excel');
        Route::get('metas', [MetaComercialControlador::class, 'index'])
            ->middleware('base.permiso:DASHBOARD-METAS-COMERCIALES')
            ->name('dashboard.metas.index');
        Route::get('metas/catalogos', [MetaComercialControlador::class, 'catalogos'])
            ->middleware('base.permiso:DASHBOARD-METAS-COMERCIALES')
            ->name('dashboard.metas.catalogos');
        Route::get('metas/excel', [MetaComercialControlador::class, 'excel'])
            ->middleware('base.permiso:DASHBOARD-METAS-COMERCIALES')
            ->name('dashboard.metas.excel');
        Route::get('metas/{meta}', [MetaComercialControlador::class, 'show'])
            ->middleware('base.permiso:DASHBOARD-METAS-COMERCIALES')
            ->name('dashboard.metas.show');
        Route::post('metas', [MetaComercialControlador::class, 'store'])
            ->middleware('base.permiso:DASHBOARD-METAS-COMERCIALES')
            ->name('dashboard.metas.store');
        Route::put('metas/{meta}', [MetaComercialControlador::class, 'update'])
            ->middleware('base.permiso:DASHBOARD-METAS-COMERCIALES')
            ->name('dashboard.metas.update');
    });
