<?php

use App\Http\Controllers\Staff\StaffController;
use Illuminate\Support\Facades\Route;

Route::get('staff/perfiles', [StaffController::class, 'perfiles']);
Route::post('staff/perfiles', [StaffController::class, 'crearPerfil']);
Route::get('staff/turnos', [StaffController::class, 'turnos']);
Route::post('staff/turnos', [StaffController::class, 'crearTurno']);
Route::get('staff/clientes', [StaffController::class, 'clientes']);
Route::get('staff/mis-clientes', [StaffController::class, 'misClientes']);
Route::post('staff/clientes', [StaffController::class, 'asignarCliente']);
Route::put('staff/clientes/{id}/seguimiento', [StaffController::class, 'actualizarObservaciones']);
Route::post('staff/clientes/{id}/finalizar', [StaffController::class, 'finalizarCliente']);
