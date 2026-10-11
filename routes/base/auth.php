<?php

use App\Http\Controllers\Api\Auth\CambiarClaveController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LoginMicrosoftController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\UsuarioActualController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', LoginController::class)->name('base.auth.login');
Route::get('/auth/microsoft/configuracion', [LoginMicrosoftController::class, 'configuracion'])
    ->name('base.auth.microsoft.configuracion');
Route::post('/auth/microsoft', [LoginMicrosoftController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('base.auth.microsoft.login');

Route::middleware('base.auth')->group(function (): void {
    Route::get('/auth/me', UsuarioActualController::class)->name('base.auth.me');
    Route::post('/auth/logout', LogoutController::class)->name('base.auth.logout');
    Route::post('/auth/cambiar-clave', CambiarClaveController::class)->name('base.auth.cambiar-clave');
});
