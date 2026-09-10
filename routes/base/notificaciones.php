<?php

use App\Http\Controllers\Api\Notificaciones\ActivarCuentaController;
use App\Http\Controllers\Api\Notificaciones\CampaniaNotificacionController;
use App\Http\Controllers\Api\Notificaciones\ComunicacionInicioAppController;
use App\Http\Controllers\Api\Notificaciones\DispositivoPushController;
use App\Http\Controllers\Api\Notificaciones\NotificacionUsuarioController;
use App\Http\Controllers\Api\Notificaciones\PlantillaCorreoController;
use App\Http\Controllers\Api\Notificaciones\PresenciaAppController;
use Illuminate\Support\Facades\Route;

Route::post('/notificaciones/activar-cuenta', ActivarCuentaController::class)->middleware('throttle:10,1');
Route::post('/notificaciones/activar-cuenta/validar', [ActivarCuentaController::class, 'validar'])->middleware('throttle:10,1');

Route::middleware(['base.auth'])->prefix('notificaciones/dispositivos-push')->group(function (): void {
    Route::post('/', [DispositivoPushController::class, 'guardar']);
    Route::delete('/', [DispositivoPushController::class, 'eliminar']);
});

Route::get('/notificaciones/comunicaciones-inicio-app', ComunicacionInicioAppController::class)
    ->middleware('base.auth');
Route::post('/notificaciones/presencia-app', PresenciaAppController::class)
    ->middleware('base.auth');

Route::middleware(['base.auth', 'base.permiso:NOTIFICACIONES-ACCESO'])->prefix('notificaciones')->group(function (): void {
    Route::get('/usuarios', [NotificacionUsuarioController::class, 'index']);
    Route::get('/usuarios/seleccion', [NotificacionUsuarioController::class, 'seleccion']);
    Route::post('/usuarios/enviar', [NotificacionUsuarioController::class, 'enviarVarios']);
    Route::get('/usuarios/lotes/recientes', [NotificacionUsuarioController::class, 'lotesRecientes']);
    Route::get('/usuarios/lotes/{lote}', [NotificacionUsuarioController::class, 'estadoLote']);
    Route::post('/usuarios/{usuario}/enviar', [NotificacionUsuarioController::class, 'enviar']);
    Route::get('/usuarios/{usuario}/historial', [NotificacionUsuarioController::class, 'historial']);
});

Route::middleware(['base.auth', 'base.permiso:NOTIFICACIONES-PLANTILLAS'])->prefix('notificaciones/plantillas')->group(function (): void {
    Route::get('/eventos', [PlantillaCorreoController::class, 'eventos']);
    Route::get('/', [PlantillaCorreoController::class, 'index']);
    Route::post('/', [PlantillaCorreoController::class, 'guardar']);
    Route::put('/{id}', [PlantillaCorreoController::class, 'guardar']);
    Route::post('/vista-previa', [PlantillaCorreoController::class, 'vistaPrevia']);
});

Route::middleware(['base.auth', 'base.permiso:NOTIFICACIONES-COMUNICADOS'])->prefix('notificaciones/campanias')->group(function (): void {
    Route::get('/catalogos', [CampaniaNotificacionController::class, 'catalogos']);
    Route::post('/vista-previa', [CampaniaNotificacionController::class, 'vistaPrevia']);
    Route::post('/imagen-app', [CampaniaNotificacionController::class, 'subirImagenApp']);
    Route::get('/', [CampaniaNotificacionController::class, 'index']);
    Route::post('/', [CampaniaNotificacionController::class, 'store']);
    Route::put('/{id}', [CampaniaNotificacionController::class, 'update']);
    Route::get('/{id}', [CampaniaNotificacionController::class, 'show']);
    Route::delete('/{id}', [CampaniaNotificacionController::class, 'destroy']);
    Route::post('/{id}/enviar', [CampaniaNotificacionController::class, 'enviar']);
    Route::post('/{id}/reenviar-errores', [CampaniaNotificacionController::class, 'reenviarErrores']);
});

Route::middleware(['base.auth', 'base.permiso:NOTIFICACIONES-HISTORIAL'])->prefix('notificaciones/historial')->group(function (): void {
    Route::get('/', [CampaniaNotificacionController::class, 'historialGeneral']);
    Route::get('/{tipo}/{id}', [CampaniaNotificacionController::class, 'historialDetalle']);
    Route::post('/ACCESO/{id}/reenviar', [CampaniaNotificacionController::class, 'reenviarAcceso']);
    Route::post('/COMUNICADO/{id}/duplicar', [CampaniaNotificacionController::class, 'duplicarComunicado']);
    Route::get('/{id}', [CampaniaNotificacionController::class, 'show']);
});
