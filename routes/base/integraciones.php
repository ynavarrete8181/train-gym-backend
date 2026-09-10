<?php

use App\Http\Controllers\Api\Integraciones\ConfiguracionIntegracionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth', 'base.permiso:INTEGRACIONES-CONFIG'])->prefix('integraciones')->group(function (): void {
    Route::get('/', [ConfiguracionIntegracionController::class, 'index']);
    Route::post('/proveedores', [ConfiguracionIntegracionController::class, 'proveedor']);
    Route::put('/proveedores/{id}', [ConfiguracionIntegracionController::class, 'proveedor']);
    Route::post('/servicios', [ConfiguracionIntegracionController::class, 'servicio']);
    Route::put('/servicios/{id}', [ConfiguracionIntegracionController::class, 'servicio']);
    Route::post('/credenciales', [ConfiguracionIntegracionController::class, 'credencial']);
    Route::get('/credenciales/{id}', [ConfiguracionIntegracionController::class, 'detalleCredencial']);
    Route::put('/credenciales/{id}', [ConfiguracionIntegracionController::class, 'credencial']);
    Route::post('/credenciales/{id}/probar', [ConfiguracionIntegracionController::class, 'probarCredencial']);
});
