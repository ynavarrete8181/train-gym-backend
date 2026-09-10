<?php

use App\Http\Controllers\Api\Entrenamiento\EntrenamientoControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('entrenamiento')->group(function (): void {
    Route::middleware(['base.permiso:ENTRENAMIENTO-EJERCICIOS'])->group(function (): void {
        Route::get('ejercicios', [EntrenamientoControlador::class, 'ejercicios'])->name('entrenamiento.ejercicios.index');
        Route::post('ejercicios', [EntrenamientoControlador::class, 'guardarEjercicio'])->name('entrenamiento.ejercicios.store');
        Route::put('ejercicios/{id}', [EntrenamientoControlador::class, 'guardarEjercicio'])->name('entrenamiento.ejercicios.update');
    });

    Route::middleware(['base.permiso:ENTRENAMIENTO-PLANES'])->group(function (): void {
        Route::get('planes', [EntrenamientoControlador::class, 'planes'])->name('entrenamiento.planes.index');
        Route::post('planes', [EntrenamientoControlador::class, 'guardarPlan'])->name('entrenamiento.planes.store');
        Route::put('planes/{id}', [EntrenamientoControlador::class, 'guardarPlan'])->name('entrenamiento.planes.update');
    });

    Route::middleware(['base.permiso:ENTRENAMIENTO-RUTINAS'])->group(function (): void {
        Route::get('rutinas', [EntrenamientoControlador::class, 'rutinas'])->name('entrenamiento.rutinas.index');
        Route::post('rutinas', [EntrenamientoControlador::class, 'guardarRutina'])->name('entrenamiento.rutinas.store');
        Route::put('rutinas/{id}', [EntrenamientoControlador::class, 'guardarRutina'])->name('entrenamiento.rutinas.update');
    });

    Route::middleware(['base.permiso:ENTRENAMIENTO-RM'])->group(function (): void {
        Route::get('registros-rm', [EntrenamientoControlador::class, 'rm'])->name('entrenamiento.rm.index');
        Route::post('registros-rm', [EntrenamientoControlador::class, 'guardarRm'])->name('entrenamiento.rm.store');
        Route::put('registros-rm/{id}', [EntrenamientoControlador::class, 'guardarRm'])->name('entrenamiento.rm.update');
        Route::get('registros-rm/ultimos', [EntrenamientoControlador::class, 'ultimosRm'])->name('entrenamiento.rm.ultimos');
    });

    Route::middleware(['base.permiso:ENTRENAMIENTO-PROGRESO'])->group(function (): void {
        Route::get('progreso', [EntrenamientoControlador::class, 'progreso'])->name('entrenamiento.progreso.index');
        Route::post('progreso', [EntrenamientoControlador::class, 'guardarProgreso'])->name('entrenamiento.progreso.store');
        Route::put('progreso/{id}', [EntrenamientoControlador::class, 'guardarProgreso'])->name('entrenamiento.progreso.update');
        Route::delete('progreso/{id}', [EntrenamientoControlador::class, 'eliminarProgreso'])->name('entrenamiento.progreso.destroy');
    });
});
