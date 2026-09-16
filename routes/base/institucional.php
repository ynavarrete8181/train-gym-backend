<?php

use App\Http\Controllers\Api\Institucional\CampoFormacionController;
use App\Http\Controllers\Api\Institucional\EstructuraInstitucionalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('institucional')->group(function () {
    // Clientes y Membresías requieren consultar sedes. La escritura institucional conserva sus permisos propios.
    Route::get('/estructura', [EstructuraInstitucionalController::class, 'index'])
        ->middleware('base.permiso:INSTITUCIONAL-SEDES,INSTITUCIONAL-UNIDADES,INSTITUCIONAL-CARRERAS-AREAS,INSTITUCIONAL-CAMPOS-AMPLIOS,GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS');

    Route::middleware('base.permiso:INSTITUCIONAL-SEDES,INSTITUCIONAL-UNIDADES,INSTITUCIONAL-CARRERAS-AREAS,INSTITUCIONAL-CAMPOS-AMPLIOS')->group(function (): void {
        Route::post('/sedes', [EstructuraInstitucionalController::class, 'sede']);
        Route::put('/sedes/{id}', [EstructuraInstitucionalController::class, 'sede']);
        Route::post('/unidades', [EstructuraInstitucionalController::class, 'unidad']);
        Route::put('/unidades/{id}', [EstructuraInstitucionalController::class, 'unidad']);
        Route::post('/carreras-areas', [EstructuraInstitucionalController::class, 'carreraArea']);
        Route::put('/carreras-areas/{id}', [EstructuraInstitucionalController::class, 'carreraArea']);
        Route::patch('/{tipo}/{id}/estado', [EstructuraInstitucionalController::class, 'estado']);

        Route::get('/campos-formacion', [CampoFormacionController::class, 'index']);
        Route::post('/campos-formacion/amplios', [CampoFormacionController::class, 'amplio']);
        Route::put('/campos-formacion/amplios/{id}', [CampoFormacionController::class, 'amplio']);
        Route::patch('/campos-formacion/amplios/{id}/estado', [CampoFormacionController::class, 'estado']);
    });
});
