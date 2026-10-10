<?php

use App\Http\Controllers\Api\Ventas\Reportes\CarteraVencidaControlador;
use App\Http\Controllers\Api\Ventas\Reportes\CobrosMetodoPagoControlador;
use App\Http\Controllers\Api\Ventas\Reportes\ResumenComercialControlador;
use App\Http\Controllers\Api\Ventas\Reportes\VentasPeriodoControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('reportes')->group(function (): void {
    Route::get('resumen-comercial', [ResumenComercialControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-COMERCIAL-RESUMEN')
        ->name('ventas.reportes.resumen-comercial');

    Route::get('cartera-vencida', [CarteraVencidaControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-CARTERA-VENCIDA')
        ->name('ventas.reportes.cartera-vencida');

    Route::get('cobros-metodo-pago', [CobrosMetodoPagoControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-COBROS-METODOS')
        ->name('ventas.reportes.cobros-metodo-pago');

    Route::get('ventas-periodo', [VentasPeriodoControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-VENTAS-PERIODO')
        ->name('ventas.reportes.ventas-periodo');
});
