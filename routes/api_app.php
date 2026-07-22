<?php

use App\Http\Controllers\Personas\AppRoutineController;
use App\Http\Controllers\Personas\AppDashboardController;
use App\Http\Controllers\Personas\AppFichaController;
use App\Http\Controllers\Personas\AppRMController;
use App\Http\Controllers\Ejercicios\AppEjercicioController;
use App\Http\Controllers\Personas\AppEvaluacionController;
use App\Http\Controllers\Personas\AppFacturaController;
use App\Http\Controllers\Personas\AppPlanesController;
use App\Http\Controllers\Acceso\AccesoController;
use App\Http\Controllers\Asistencia\AsistenciaController;
use App\Http\Controllers\Reservas\ReservaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile App (BFF) Routes
|--------------------------------------------------------------------------
|
| Aquí se registran las rutas exclusivas para el consumo de la aplicación
| móvil (React Native). Estos endpoints deben estar optimizados para
| devolver estructuras JSON consolidadas y ligeras.
|
*/

use App\Http\Controllers\Personas\AppProgressController;

// Rutas del Dashboard
Route::get('dashboard', [AppDashboardController::class, 'getDashboardSummary']);

// Rutas de Progreso (Evolución)
Route::get('progreso', [AppProgressController::class, 'getProgress']);

// Rutas de Rutina de Entrenamiento
Route::get('rutinas', [AppRoutineController::class, 'getRoutineByDay']);
Route::get('rutinas/hoy', [AppRoutineController::class, 'getTodayRoutine']);
Route::post('rutinas/calcular-rm-temporal', [AppRoutineController::class, 'calculateTemporaryRmLoads']);
Route::delete('rutinas/rm-temporal', [AppRoutineController::class, 'clearTemporaryRm']);
Route::post('rutinas/ejecutar', [AppRoutineController::class, 'registerExecution']);

// Rutas de Detalle de Ejercicios
Route::get('ejercicios/{id}', [AppEjercicioController::class, 'getExerciseDetail']);

// Rutas de Fichas (Ficha Técnica y Evaluaciones)
Route::get('fichas', [AppFichaController::class, 'getFichas']);

// Rutas de Evaluaciones Físicas
Route::get('evaluaciones', [AppEvaluacionController::class, 'getEvaluaciones']);

// Rutas de RM (Récords Personales)
Route::get('rms', [AppRMController::class, 'getRMs']);

// Rutas de Facturación
Route::get('facturas', [AppFacturaController::class, 'getFacturas']);

// Rutas de Planes / Membresías
Route::get('planes', [AppPlanesController::class, 'getPlanes']);

// Rutas de Acceso, Reservas y Asistencia
Route::get('acceso', [AccesoController::class, 'credencialApp']);
Route::get('asistencias', [AsistenciaController::class, 'index']);
Route::get('reservas', [ReservaController::class, 'index']);
Route::get('reservas/membresias', [ReservaController::class, 'membresiasApp']);
Route::get('reservas/disponibilidad', [ReservaController::class, 'disponibilidad']);
Route::post('reservas', [ReservaController::class, 'store']);
Route::post('reservas/{id}/cancelar', [ReservaController::class, 'cancelar']);
