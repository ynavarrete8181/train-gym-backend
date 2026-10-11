<?php

use App\Http\Controllers\Api\Base\EstadoSistemaController;
use App\Http\Controllers\Api\Seguridad\AvisoUsuarioController;
use Illuminate\Support\Facades\Route;

Route::get('/salud', EstadoSistemaController::class)->name('base.salud');

Route::middleware('base.auth')->prefix('avisos')->group(function (): void {
    Route::get('/', [AvisoUsuarioController::class, 'index'])->name('base.avisos.index');
    Route::patch('/{aviso}/leido', [AvisoUsuarioController::class, 'marcarLeido'])->name('base.avisos.leido');
});
