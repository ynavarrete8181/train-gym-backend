<?php

use App\Http\Controllers\Api\Resultados\ResultadoControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('resultados')->group(function (): void {
    Route::get('resumen', [ResultadoControlador::class, 'resumen'])->middleware('base.permiso:RESULTADOS-RESUMEN')->name('resultados.resumen');
    Route::get('asistencia', [ResultadoControlador::class, 'asistencia'])->middleware('base.permiso:RESULTADOS-ASISTENCIA')->name('resultados.asistencia');
    Route::get('ventas', [ResultadoControlador::class, 'ventas'])->middleware('base.permiso:RESULTADOS-VENTAS')->name('resultados.ventas');
    Route::get('progreso', [ResultadoControlador::class, 'progreso'])->middleware('base.permiso:RESULTADOS-PROGRESO')->name('resultados.progreso');
});
