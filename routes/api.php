<?php

use App\Http\Controllers\Api\App\DashboardDeportistaController;
use App\Http\Controllers\Api\App\EjercicioDetalleController;
use App\Http\Controllers\Api\App\MiActividadController;
use App\Http\Controllers\Api\App\MiPortalController;
use App\Http\Controllers\Api\App\MiRutinaController;
use App\Http\Controllers\Api\App\MisFacturasController;
use App\Http\Controllers\Api\App\MisMembresiasController;
use App\Http\Controllers\Api\App\MisReservasController;
use App\Http\Controllers\Api\App\PlanesDisponiblesController;
use App\Http\Controllers\Api\App\TiendaProductosController;
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

// Rutina personal: acceso condicionado a membresía activa y vigente.
Route::middleware('base.auth')
    ->get('app/rutinas', MiRutinaController::class)
    ->name('app.rutinas');

Route::middleware('base.auth')->controller(EjercicioDetalleController::class)->group(function (): void {
    Route::get('app/ejercicios/{id}', 'show')->name('app.ejercicios.show');
    Route::post('app/rutinas/ejecutar', 'ejecutar')->name('app.rutinas.ejecutar');
});

// Dashboard personal: únicamente datos del usuario autenticado.
Route::middleware('base.auth')
    ->get('app/dashboard', DashboardDeportistaController::class)
    ->name('app.dashboard');

Route::middleware('base.auth')->controller(MiActividadController::class)->group(function (): void {
    Route::get('app/rms', 'rms')->name('app.rms');
    Route::get('app/fichas', 'fichas')->name('app.fichas');
    Route::get('app/progreso', 'progreso')->name('app.progreso');
});

Route::middleware('base.auth')->get('app/portal', MiPortalController::class)->name('app.portal');

Route::middleware('base.auth')
    ->get('app/facturas', MisFacturasController::class)
    ->name('app.facturas');

Route::middleware('base.auth')->controller(MisReservasController::class)->group(function (): void {
    Route::get('app/reservas/disponibilidad', 'disponibilidad');
    Route::get('app/reservas/membresias', 'membresias');
    Route::get('app/reservas', 'index');
    Route::post('app/reservas', 'store');
    Route::post('app/reservas/{id}/cancelar', 'cancelar');
});

// Asignaciones propias del deportista autenticado: incluye detalle contractual.
Route::middleware('base.auth')
    ->get('app/mis-membresias', MisMembresiasController::class)
    ->name('app.mis-membresias');

// Planes activos visibles para el deportista; separado del catálogo administrativo.
Route::middleware('base.auth')
    ->get('app/planes', PlanesDisponiblesController::class)
    ->name('app.planes');

// Catálogo de lectura para deportistas autenticados: no expone gestión administrativa.
Route::middleware('base.auth')
    ->get('app/tienda/productos', TiendaProductosController::class)
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
