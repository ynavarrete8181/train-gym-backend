<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí es donde puedes registrar las rutas API para tu aplicación. Estas
| rutas son cargadas por el RouteServiceProvider y todas ellas serán
| asignadas al grupo "api". ¡Haz algo grande!
|
*/

// Rutas de Autenticación (Tienen sus propios middlewares internos)
require __DIR__ . '/api_auth.php';

// Rutas del módulo de Notificaciones
require __DIR__ . '/api_notificaciones.php';

// Rutas de Gimnasio (Agrupadas bajo el prefijo 'gimnasio' y autenticadas)
Route::middleware('auth:sanctum')->prefix('gimnasio')->group(function () {
    
    // Módulo de Seguridad y Auditoría
    require __DIR__ . '/api_seguridad.php';
    
    // Módulo de Servicios y Horarios
    require __DIR__ . '/api_servicios.php';

    // Módulo de Personas y Membresías
    require __DIR__ . '/api_personas.php';
    
    // Módulo de Entrenamiento (Rutinas, Evaluaciones)
    require __DIR__ . '/api_entrenamiento.php';

    // Módulo de Ventas y Punto de Venta
    require __DIR__ . '/api_ventas.php';

});

// Rutas de Inventario (Agrupadas bajo el prefijo 'inventario' y autenticadas)
Route::middleware('auth:sanctum')->prefix('inventario')->group(function () {
    require __DIR__ . '/api_inventario.php';
});

// Rutas exclusivas para la App Móvil (BFF)
Route::middleware('auth:sanctum')->prefix('app')->group(function () {
    require __DIR__ . '/api_app.php';
});
