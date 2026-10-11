<?php

use App\Http\Controllers\Api\Acceso\AccesoControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('acceso')->group(function (): void {
    Route::middleware(['base.permiso:ACCESO-DISPOSITIVOS'])->group(function (): void {
        Route::get('dispositivos', [AccesoControlador::class, 'dispositivos'])->name('acceso.dispositivos.index');
        Route::post('dispositivos', [AccesoControlador::class, 'guardarDispositivo'])->name('acceso.dispositivos.store');
        Route::put('dispositivos/{id}', [AccesoControlador::class, 'guardarDispositivo'])->name('acceso.dispositivos.update');
    });

    Route::middleware(['base.permiso:ACCESO-CREDENCIALES'])->group(function (): void {
        Route::get('credenciales', [AccesoControlador::class, 'credenciales'])->name('acceso.credenciales.index');
        Route::post('credenciales', [AccesoControlador::class, 'guardarCredencial'])->name('acceso.credenciales.store');
        Route::put('credenciales/{id}', [AccesoControlador::class, 'guardarCredencial'])->name('acceso.credenciales.update');
    });

    Route::middleware(['base.permiso:ACCESO-EVENTOS'])->group(function (): void {
        Route::get('eventos', [AccesoControlador::class, 'eventos'])->name('acceso.eventos.index');
        Route::post('eventos', [AccesoControlador::class, 'registrarEvento'])->name('acceso.eventos.store');
    });

    Route::middleware(['base.permiso:ACCESO-ASISTENCIA'])->group(function (): void {
        Route::get('asistencias', [AccesoControlador::class, 'asistencias'])->name('acceso.asistencias.index');
        Route::post('asistencias', [AccesoControlador::class, 'registrarAsistencia'])->name('acceso.asistencias.store');
    });
});
