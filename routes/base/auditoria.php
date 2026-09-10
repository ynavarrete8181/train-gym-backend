<?php

use App\Http\Controllers\Api\Auditoria\AuditoriaControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('auditoria')->group(function (): void {
    Route::get('eventos', [AuditoriaControlador::class, 'eventos'])->middleware('base.permiso:AUDITORIA-EVENTOS')->name('auditoria.eventos');
    Route::get('accesos', [AuditoriaControlador::class, 'accesos'])->middleware('base.permiso:AUDITORIA-ACCESOS')->name('auditoria.accesos');
    Route::get('resumen', [AuditoriaControlador::class, 'resumen'])->middleware('base.permiso:AUDITORIA-RESUMEN')->name('auditoria.resumen');
    Route::get('logs', [AuditoriaControlador::class, 'logs'])->middleware('base.permiso:AUDITORIA-LOGS')->name('auditoria.logs');
});
