<?php

use App\Http\Controllers\Logs\LogSistemaController;
use Illuminate\Support\Facades\Route;

Route::get('logs/eventos', [LogSistemaController::class, 'eventos']);
Route::get('logs/resumen', [LogSistemaController::class, 'resumen']);
Route::get('logs/excepciones', [LogSistemaController::class, 'excepciones']);
Route::get('logs/integraciones', [LogSistemaController::class, 'integraciones']);
