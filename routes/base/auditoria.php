<?php

use App\Http\Controllers\Api\Auditoria\AuditoriaControlador;
use App\Http\Controllers\Api\Auditoria\TrazabilidadComercialControlador;
use App\Http\Controllers\Api\Auditoria\ReportesAuditoriaControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('auditoria')->group(function (): void {
    Route::get('eventos', [AuditoriaControlador::class, 'eventos'])->middleware('base.permiso:AUDITORIA-EVENTOS')->name('auditoria.eventos');
    Route::get('accesos', [AuditoriaControlador::class, 'accesos'])->middleware('base.permiso:AUDITORIA-ACCESOS')->name('auditoria.accesos');
    Route::get('resumen', [AuditoriaControlador::class, 'resumen'])->middleware('base.permiso:AUDITORIA-RESUMEN')->name('auditoria.resumen');
    Route::get('logs', [AuditoriaControlador::class, 'logs'])->middleware('base.permiso:AUDITORIA-LOGS')->name('auditoria.logs');
    Route::get('integraciones', [AuditoriaControlador::class, 'integraciones'])
        ->middleware('base.permiso:AUDITORIA-INTEGRACIONES')
        ->name('auditoria.integraciones');
    Route::get('reportes', [ReportesAuditoriaControlador::class, 'index'])
        ->middleware('base.permiso:AUDITORIA-REPORTES')
        ->name('auditoria.reportes');
    Route::get('reportes/excel', [ReportesAuditoriaControlador::class, 'excel'])
        ->middleware('base.permiso:AUDITORIA-REPORTES')
        ->name('auditoria.reportes.excel');
    Route::get('trazabilidad-comercial', [TrazabilidadComercialControlador::class, 'index'])
        ->middleware('base.permiso:AUDITORIA-TRAZABILIDAD-COMERCIAL')
        ->name('auditoria.trazabilidad-comercial');
    Route::get('trazabilidad-comercial/excel', [TrazabilidadComercialControlador::class, 'excel'])
        ->middleware('base.permiso:AUDITORIA-TRAZABILIDAD-COMERCIAL')
        ->name('auditoria.trazabilidad-comercial.excel');
});
