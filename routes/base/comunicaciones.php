<?php

use App\Http\Controllers\Api\Comunicaciones\ComunicacionControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth', 'base.permiso:COMUNICACIONES-TIPOS'])->prefix('comunicaciones/tipos')->group(function (): void {
    Route::get('/', [ComunicacionControlador::class, 'tipos']);
    Route::post('/', [ComunicacionControlador::class, 'guardarTipo']);
    Route::put('/{id}', [ComunicacionControlador::class, 'guardarTipo']);
});

Route::middleware(['base.auth', 'base.permiso:COMUNICACIONES-SEGMENTOS'])->prefix('comunicaciones/segmentos')->group(function (): void {
    Route::get('/', [ComunicacionControlador::class, 'segmentos']);
    Route::post('/', [ComunicacionControlador::class, 'guardarSegmento']);
    Route::put('/{id}', [ComunicacionControlador::class, 'guardarSegmento']);
});

Route::middleware(['base.auth', 'base.permiso:COMUNICACIONES-MENSAJES'])->prefix('comunicaciones/mensajes')->group(function (): void {
    Route::get('/', [ComunicacionControlador::class, 'mensajes']);
    Route::post('/', [ComunicacionControlador::class, 'guardarMensaje']);
    Route::put('/{id}', [ComunicacionControlador::class, 'guardarMensaje']);
});

Route::middleware(['base.auth', 'base.permiso:COMUNICACIONES-PROGRAMACIONES'])->prefix('comunicaciones/programaciones')->group(function (): void {
    Route::get('/', [ComunicacionControlador::class, 'programaciones']);
    Route::post('/', [ComunicacionControlador::class, 'guardarProgramacion']);
    Route::put('/{id}', [ComunicacionControlador::class, 'guardarProgramacion']);
});
