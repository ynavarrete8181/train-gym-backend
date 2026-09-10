<?php

use App\Http\Controllers\Api\Institucional\CampoFormacionController;
use App\Http\Controllers\Api\Institucional\EstructuraInstitucionalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth', 'base.permiso:INSTITUCIONAL-SEDES,INSTITUCIONAL-UNIDADES,INSTITUCIONAL-CARRERAS-AREAS,INSTITUCIONAL-CAMPOS-AMPLIOS'])->prefix('institucional')->group(function () {
    Route::get('/estructura', [EstructuraInstitucionalController::class, 'index']);
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
