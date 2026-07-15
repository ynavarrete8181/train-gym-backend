<?php

use App\Http\Controllers\Notificaciones\NotificacionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('gimnasio')->group(function () {
    Route::get('notificaciones', [NotificacionController::class, 'index']);
    Route::post('notificaciones', [NotificacionController::class, 'store']);
    Route::post('notificaciones/{id}/leer', [NotificacionController::class, 'markAsRead']);
});

Route::middleware('auth:sanctum')->prefix('app')->group(function () {
    Route::get('notificaciones', [NotificacionController::class, 'index']);
    Route::post('notificaciones/{id}/leer', [NotificacionController::class, 'markAsRead']);
    Route::post('notificaciones/dispositivos', [NotificacionController::class, 'registerDevice']);
});
