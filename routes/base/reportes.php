<?php

use App\Http\Controllers\Api\Reportes\ReporteControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('reportes')->group(function (): void {
    Route::get('disponibles', [ReporteControlador::class, 'disponibles'])->middleware('base.permiso:REPORTES-DISPONIBLES')->name('reportes.disponibles');
    Route::get('historial', [ReporteControlador::class, 'historial'])->middleware('base.permiso:REPORTES-HISTORIAL')->name('reportes.historial');
    Route::post('ejecuciones', [ReporteControlador::class, 'generar'])->middleware('base.permiso:REPORTES-DISPONIBLES')->name('reportes.generar');
});
