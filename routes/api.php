<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['base.auth']]);
require __DIR__.'/channels.php';

Route::get('health', function () {
    try {
        DB::select('select 1');

        return response()->json([
            'status' => 'ok',
            'database' => 'ok',
            'app' => config('app.name'),
            'environment' => app()->environment(),
        ]);
    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'status' => 'error',
            'database' => 'unavailable',
        ], 503);
    }
});

// Dashboard personal: únicamente datos del usuario autenticado.
Route::middleware('base.auth')
    ->get('app/dashboard', \App\Http\Controllers\Api\App\DashboardDeportistaController::class)
    ->name('app.dashboard');

// Catálogo de lectura para deportistas autenticados: no expone gestión administrativa.
Route::middleware('base.auth')
    ->get('app/tienda/productos', \App\Http\Controllers\Api\App\TiendaProductosController::class)
    ->name('app.tienda.productos');

Route::prefix('base')->group(function (): void {
    require __DIR__.'/base/base.php';
    require __DIR__.'/base/dashboard.php';
    require __DIR__.'/base/metas.php';
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
