<?php

use App\Http\Controllers\Api\Metas\MetaComercialControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth', 'base.permiso:DASHBOARD-METAS-COMERCIALES'])
    ->prefix('metas')
    ->group(function (): void {
        Route::get('/', [MetaComercialControlador::class, 'index'])->name('metas.index');
        Route::get('/catalogos', [MetaComercialControlador::class, 'catalogos'])->name('metas.catalogos');
        Route::get('/excel', [MetaComercialControlador::class, 'excel'])->name('metas.excel');
        Route::get('/{meta}', [MetaComercialControlador::class, 'show'])->name('metas.show');
        Route::post('/', [MetaComercialControlador::class, 'store'])->name('metas.store');
        Route::put('/{meta}', [MetaComercialControlador::class, 'update'])->name('metas.update');
    });
