<?php

use App\Http\Controllers\Api\Dashboard\DashboardEjecutivoControlador;
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
    });
