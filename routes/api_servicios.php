<?php

use App\Http\Controllers\Horarios\HorarioGymController;
use App\Http\Controllers\Servicios\CategoriaServicioController;
use App\Http\Controllers\Servicios\ServicioController;
use Illuminate\Support\Facades\Route;

Route::get('horarios', [HorarioGymController::class, 'index']);
Route::get('horarios/{id}', [HorarioGymController::class, 'show']);
Route::post('horarios', [HorarioGymController::class, 'store']);
Route::put('horarios/{id}', [HorarioGymController::class, 'update']);
Route::delete('horarios/{id}', [HorarioGymController::class, 'destroy']);

Route::get('categoria-servicio', [CategoriaServicioController::class, 'index']);
Route::get('categoria-servicio/{id}', [CategoriaServicioController::class, 'show']);
Route::post('categoria-servicio', [CategoriaServicioController::class, 'store']);
Route::put('categoria-servicio/{id}', [CategoriaServicioController::class, 'update']);
Route::delete('categoria-servicio/{id}', [CategoriaServicioController::class, 'destroy']);
Route::get('categoria-servicio/{idCategoria}/servicios', [CategoriaServicioController::class, 'serviciosByCategoria']);

Route::get('servicios', [ServicioController::class, 'index']);
Route::get('servicios/{id}', [ServicioController::class, 'show']);
Route::post('servicios', [ServicioController::class, 'store']);
Route::put('servicios/{id}', [ServicioController::class, 'update']);
Route::delete('servicios/{id}', [ServicioController::class, 'destroy']);
