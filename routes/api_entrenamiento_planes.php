<?php

use App\Http\Controllers\Entrenamiento\PlanEntrenamientoController;
use Illuminate\Support\Facades\Route;

Route::get('planes-entrenamiento', [PlanEntrenamientoController::class, 'index']);
Route::post('planes-entrenamiento', [PlanEntrenamientoController::class, 'store']);
Route::get('planes-entrenamiento/personas-disponibles', [PlanEntrenamientoController::class, 'personasDisponibles']);
Route::get('planes-entrenamiento/{id}', [PlanEntrenamientoController::class, 'show']);
Route::put('planes-entrenamiento/{id}', [PlanEntrenamientoController::class, 'update']);
Route::delete('planes-entrenamiento/{id}', [PlanEntrenamientoController::class, 'destroy']);
Route::get('planes-entrenamiento/{planId}/asignaciones', [PlanEntrenamientoController::class, 'assignments']);
Route::post('planes-entrenamiento/{planId}/asignaciones', [PlanEntrenamientoController::class, 'storeAssignment']);
Route::put('planes-entrenamiento/{planId}/asignaciones/grupo', [PlanEntrenamientoController::class, 'syncGroupAssignment']);
Route::delete('planes-entrenamiento/{planId}/asignaciones/grupo', [PlanEntrenamientoController::class, 'destroyGroupAssignment']);
Route::put('planes-entrenamiento/{planId}/asignaciones/{assignmentId}', [PlanEntrenamientoController::class, 'updateAssignment']);
Route::delete('planes-entrenamiento/{planId}/asignaciones/{assignmentId}', [PlanEntrenamientoController::class, 'destroyAssignment']);
Route::post('planes-entrenamiento/{planId}/dias/sync', [PlanEntrenamientoController::class, 'syncDay']);
Route::post('planes-entrenamiento/{planId}/semanas/duplicar', [PlanEntrenamientoController::class, 'duplicateWeek']);
Route::delete('planes-entrenamiento/{planId}/dias/{dayId}', [PlanEntrenamientoController::class, 'destroyDay']);
