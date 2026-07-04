<?php

use App\Http\Controllers\Personas\PersonaController;
use App\Http\Controllers\Personas\FichaTecnicaController;
use App\Http\Controllers\Personas\MembresiaController;
use Illuminate\Support\Facades\Route;

Route::get('personas/buscar', [PersonaController::class, 'buscar']);
Route::post('personas/foto/quitar-fondo', [PersonaController::class, 'removePhotoBackground']);
Route::get('personas', [PersonaController::class, 'index']);
Route::get('personas/{id}', [PersonaController::class, 'show']);
Route::post('personas', [PersonaController::class, 'store']);
Route::post('personas/{id}', [PersonaController::class, 'update']);
Route::put('personas/{id}', [PersonaController::class, 'update']);
Route::post('personas/{id}/estado', [PersonaController::class, 'changeStatus']);

Route::get('fichas-tecnicas', [FichaTecnicaController::class, 'index']);
Route::post('fichas-tecnicas', [FichaTecnicaController::class, 'store']);
Route::put('fichas-tecnicas/{id}', [FichaTecnicaController::class, 'update']);
Route::delete('fichas-tecnicas/{id}', [FichaTecnicaController::class, 'destroy']);

Route::get('membresias', [MembresiaController::class, 'catalogo']);
Route::post('membresias', [MembresiaController::class, 'storeCatalogo']);
Route::put('membresias/{id}', [MembresiaController::class, 'updateCatalogo']);
Route::get('membresias/{id}/precios', [MembresiaController::class, 'precios']);
Route::post('membresias/{id}/precios', [MembresiaController::class, 'storePrecio']);
Route::put('membresia-precios/{id}', [MembresiaController::class, 'updatePrecio']);
Route::delete('membresia-precios/{id}', [MembresiaController::class, 'destroyPrecio']);
Route::get('membresias/asignaciones', [MembresiaController::class, 'asignaciones']);
Route::post('membresias/asignaciones/lote', [MembresiaController::class, 'storeAsignacionLote']);
Route::post('membresias/asignaciones', [MembresiaController::class, 'storeAsignacion']);
Route::put('membresias/asignaciones/{id}', [MembresiaController::class, 'updateAsignacion']);
Route::delete('membresias/asignaciones/{id}', [MembresiaController::class, 'destroyAsignacion']);
Route::get('membresias/socios', [MembresiaController::class, 'socios']);
