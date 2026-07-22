<?php

use App\Http\Controllers\Notificaciones\NotificacionController;
use App\Http\Controllers\Notificaciones\CumpleanosConfigController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('gimnasio')->group(function () {
    Route::get('notificaciones', [NotificacionController::class, 'index']);
    Route::post('notificaciones', [NotificacionController::class, 'store']);
    Route::get('notificaciones/cumpleanos-config', [CumpleanosConfigController::class, 'show']);
    Route::put('notificaciones/cumpleanos-config', [CumpleanosConfigController::class, 'update']);
    Route::get('notificaciones/cumpleanos-historial', [CumpleanosConfigController::class, 'history']);
    Route::post('notificaciones/cumpleanos-historial/{destinatarioId}/reenviar', [CumpleanosConfigController::class, 'resend']);
    Route::post('notificaciones/leer-todas', [NotificacionController::class, 'markAllAsRead']);
    Route::post('notificaciones/{id}/leer', [NotificacionController::class, 'markAsRead']);
});

Route::middleware('auth:sanctum')->prefix('app')->group(function () {
    Route::get('notificaciones', [NotificacionController::class, 'index']);
    Route::post('notificaciones/leer-todas', [NotificacionController::class, 'markAllAsRead']);
    Route::post('notificaciones/{id}/leer', [NotificacionController::class, 'markAsRead']);
    Route::post('notificaciones/dispositivos', [NotificacionController::class, 'registerDevice']);
});
