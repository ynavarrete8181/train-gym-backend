<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Seguridad\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->post('auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('auth/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->get('auth/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->post('auth/cambiar-password-temporal', [UsuarioController::class, 'changeTemporaryPassword']);
