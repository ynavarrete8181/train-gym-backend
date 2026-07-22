<?php

use App\Http\Controllers\Asistencia\AsistenciaController;
use Illuminate\Support\Facades\Route;

Route::get('asistencia', [AsistenciaController::class, 'index']);
Route::post('asistencia/check-in', [AsistenciaController::class, 'registrar']);
