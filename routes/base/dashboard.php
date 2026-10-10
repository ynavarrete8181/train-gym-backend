<?php

use App\Http\Controllers\Api\Dashboard\DashboardEjecutivoControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])
    ->prefix('dashboard')
    ->group(function (): void {
        Route::get('ejecutivo', DashboardEjecutivoControlador::class)
            ->middleware('base.permiso:DASHBOARD-EJECUTIVO')
            ->name('dashboard.ejecutivo');
    });
