<?php

use App\Http\Controllers\Api\Gimnasio\Reportes\ClientesReporteControlador;
use Illuminate\Support\Facades\Route;

// Reportes del módulo Gimnasio (dentro del prefijo /gimnasio).
Route::prefix('reportes')->group(function (): void {
    Route::get('clientes', [ClientesReporteControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-CLIENTES')
        ->name('gimnasio.reportes.clientes');
    Route::get('clientes/excel', [ClientesReporteControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-CLIENTES')
        ->name('gimnasio.reportes.clientes.excel');
});
