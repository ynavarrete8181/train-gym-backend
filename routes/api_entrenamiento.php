<?php

use App\Http\Controllers\Ejercicios\EjercicioController;
use App\Http\Controllers\Personas\EvaluacionRmController;
use App\Http\Controllers\Personas\PlanRutinaController;
use App\Http\Controllers\Personas\EjecucionController;
use App\Http\Controllers\Personas\ReporteEvolucionController;
use Illuminate\Support\Facades\Route;

Route::get('evaluaciones', [EvaluacionRmController::class, 'indexEvaluaciones']);
Route::post('evaluaciones', [EvaluacionRmController::class, 'storeEvaluacion']);
Route::put('evaluaciones/{id}', [EvaluacionRmController::class, 'updateEvaluacion']);
Route::delete('evaluaciones/{id}', [EvaluacionRmController::class, 'destroyEvaluacion']);

Route::get('rm-registros', [EvaluacionRmController::class, 'indexRm']);
Route::post('rm-registros', [EvaluacionRmController::class, 'storeRm']);
Route::put('rm-registros/{id}', [EvaluacionRmController::class, 'updateRm']);
Route::delete('rm-registros/{id}', [EvaluacionRmController::class, 'destroyRm']);

require __DIR__ . '/api_entrenamiento_planes.php';
require __DIR__ . '/api_entrenamiento_plantillas.php';

Route::get('planes', [PlanRutinaController::class, 'indexPlanes']);
Route::post('planes', [PlanRutinaController::class, 'storePlan']);
Route::put('planes/{id}', [PlanRutinaController::class, 'updatePlan']);
Route::delete('planes/{id}', [PlanRutinaController::class, 'destroyPlan']);
Route::post('planes/{id}/duplicar', [PlanRutinaController::class, 'duplicatePlan']);

Route::get('planes/{planId}/rutinas', [PlanRutinaController::class, 'indexRutinas']);
Route::post('planes/{planId}/rutinas', [PlanRutinaController::class, 'storeRutina']);
Route::put('planes/{planId}/rutinas/{id}', [PlanRutinaController::class, 'updateRutina']);
Route::delete('planes/{planId}/rutinas/{id}', [PlanRutinaController::class, 'destroyRutina']);
Route::post('planes/{planId}/rutinas/duplicar-semana', [PlanRutinaController::class, 'duplicateSemana']);
Route::post('planes/{planId}/rutinas/batch', [PlanRutinaController::class, 'saveBatchRutinas']);

Route::get('rutina-plantillas', [PlanRutinaController::class, 'indexPlantillas']);
Route::post('planes/{planId}/rutina-plantillas', [PlanRutinaController::class, 'saveTemplateFromWeek']);
Route::post('planes/{planId}/rutina-plantillas/aplicar', [PlanRutinaController::class, 'applyTemplateToPlan']);
Route::delete('rutina-plantillas/{id}', [PlanRutinaController::class, 'destroyTemplate']);

Route::get('ejecucion/planes', [EjecucionController::class, 'planes']);
Route::get('ejecuciones', [EjecucionController::class, 'index']);
Route::get('ejecuciones/historial', [EjecucionController::class, 'history']);
    Route::get('ejecuciones/progreso', [EjecucionController::class, 'progreso']);
Route::post('ejecuciones', [EjecucionController::class, 'store']);

Route::get('reportes/evolucion', [ReporteEvolucionController::class, 'index']);

// Catálogo de Ejercicios
Route::apiResource('ejercicios', EjercicioController::class);
