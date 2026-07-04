<?php

use App\Http\Controllers\Entrenamiento\PlantillaSemanalController;
use Illuminate\Support\Facades\Route;

Route::get('plantillas-semanales', [PlantillaSemanalController::class, 'index']);
Route::post('plantillas-semanales', [PlantillaSemanalController::class, 'store']);
Route::get('plantillas-semanales/{id}', [PlantillaSemanalController::class, 'show']);
Route::put('plantillas-semanales/{id}', [PlantillaSemanalController::class, 'update']);
Route::post('plantillas-semanales/{plantillaId}/dias/sync', [PlantillaSemanalController::class, 'syncDay']);
