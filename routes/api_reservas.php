<?php

use App\Http\Controllers\Reservas\ReservaController;
use Illuminate\Support\Facades\Route;

Route::get('reservas', [ReservaController::class, 'index']);
Route::get('reservas/disponibilidad', [ReservaController::class, 'disponibilidad']);
Route::get('reservas/reporte-diario', [ReservaController::class, 'reporteDiario']);
Route::post('reservas/generar-cupos', [ReservaController::class, 'generarCupos']);
Route::post('reservas', [ReservaController::class, 'store']);
Route::post('reservas/{id}/cancelar', [ReservaController::class, 'cancelar']);
