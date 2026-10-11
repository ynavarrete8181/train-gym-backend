<?php

use App\Http\Controllers\Api\Entrenamiento\EntrenamientoControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('entrenamiento')->group(function (): void {
    // Lecturas necesarias para la ficha integral del cliente. La escritura conserva su permiso específico.
    Route::get('ejercicios', [EntrenamientoControlador::class, 'ejercicios'])
        ->middleware('base.permiso:ENTRENAMIENTO-EJERCICIOS,GIMNASIO-DEPORTISTAS')
        ->name('entrenamiento.ejercicios.index');
    Route::middleware(['base.permiso:ENTRENAMIENTO-EJERCICIOS'])->group(function (): void {
        Route::post('ejercicios', [EntrenamientoControlador::class, 'guardarEjercicio'])->name('entrenamiento.ejercicios.store');
        Route::put('ejercicios/{id}', [EntrenamientoControlador::class, 'guardarEjercicio'])->name('entrenamiento.ejercicios.update');
    });

    Route::get('planes', [EntrenamientoControlador::class, 'planes'])
        ->middleware('base.permiso:ENTRENAMIENTO-PLANES,GIMNASIO-DEPORTISTAS')
        ->name('entrenamiento.planes.index');
    Route::middleware(['base.permiso:ENTRENAMIENTO-PLANES'])->group(function (): void {
        Route::post('planes', [EntrenamientoControlador::class, 'guardarPlan'])->name('entrenamiento.planes.store');
        Route::put('planes/{id}', [EntrenamientoControlador::class, 'guardarPlan'])->name('entrenamiento.planes.update');
    });

    Route::get('rutinas', [EntrenamientoControlador::class, 'rutinas'])
        ->middleware('base.permiso:ENTRENAMIENTO-RUTINAS,GIMNASIO-DEPORTISTAS')
        ->name('entrenamiento.rutinas.index');
    Route::middleware(['base.permiso:ENTRENAMIENTO-RUTINAS'])->group(function (): void {
        Route::post('rutinas', [EntrenamientoControlador::class, 'guardarRutina'])->name('entrenamiento.rutinas.store');
        Route::put('rutinas/{id}', [EntrenamientoControlador::class, 'guardarRutina'])->name('entrenamiento.rutinas.update');
    });

    Route::get('registros-rm', [EntrenamientoControlador::class, 'rm'])
        ->middleware('base.permiso:ENTRENAMIENTO-RM,GIMNASIO-DEPORTISTAS')
        ->name('entrenamiento.rm.index');
    Route::get('registros-rm/ultimos', [EntrenamientoControlador::class, 'ultimosRm'])
        ->middleware('base.permiso:ENTRENAMIENTO-RM,GIMNASIO-DEPORTISTAS')
        ->name('entrenamiento.rm.ultimos');
    Route::middleware(['base.permiso:ENTRENAMIENTO-RM'])->group(function (): void {
        Route::post('registros-rm', [EntrenamientoControlador::class, 'guardarRm'])->name('entrenamiento.rm.store');
        Route::put('registros-rm/{id}', [EntrenamientoControlador::class, 'guardarRm'])->name('entrenamiento.rm.update');
    });

    Route::get('progreso', [EntrenamientoControlador::class, 'progreso'])
        ->middleware('base.permiso:ENTRENAMIENTO-PROGRESO,GIMNASIO-DEPORTISTAS')
        ->name('entrenamiento.progreso.index');
    Route::middleware(['base.permiso:ENTRENAMIENTO-PROGRESO'])->group(function (): void {
        Route::post('progreso', [EntrenamientoControlador::class, 'guardarProgreso'])->name('entrenamiento.progreso.store');
        Route::put('progreso/{id}', [EntrenamientoControlador::class, 'guardarProgreso'])->name('entrenamiento.progreso.update');
        Route::delete('progreso/{id}', [EntrenamientoControlador::class, 'eliminarProgreso'])->name('entrenamiento.progreso.destroy');
    });
});
