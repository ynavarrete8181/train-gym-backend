<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['base.auth']]);
require __DIR__.'/channels.php';

Route::prefix('base')->group(function (): void {
    require __DIR__.'/base/base.php';
    require __DIR__.'/base/auth.php';
    require __DIR__.'/base/menu.php';
    require __DIR__.'/base/seguridad.php';
    require __DIR__.'/base/institucional.php';
    require __DIR__.'/base/notificaciones.php';
    require __DIR__.'/base/integraciones.php';
    require __DIR__.'/base/configuracion.php';
    require __DIR__.'/base/gimnasio.php';
    require __DIR__.'/base/entrenamiento.php';
    require __DIR__.'/base/inventario.php';
    require __DIR__.'/base/ventas.php';
    require __DIR__.'/base/acceso.php';
    require __DIR__.'/base/resultados.php';
    require __DIR__.'/base/comunicaciones.php';
    require __DIR__.'/base/reportes.php';
    require __DIR__.'/base/auditoria.php';
});

Route::prefix('gimnasio')->as('gimnasio.alias.')->group(function (): void {
    require __DIR__.'/gimnasio/dominio.php';
    require __DIR__.'/base/entrenamiento.php';
    require __DIR__.'/base/inventario.php';
    require __DIR__.'/base/ventas.php';
    require __DIR__.'/base/acceso.php';
    require __DIR__.'/base/resultados.php';
    require __DIR__.'/base/comunicaciones.php';
    require __DIR__.'/base/reportes.php';
    require __DIR__.'/base/auditoria.php';
});
require __DIR__.'/../routes/test_update.php';
