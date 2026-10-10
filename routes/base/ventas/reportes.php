<?php

use App\Http\Controllers\Api\Ventas\Reportes\CarteraVencidaControlador;
use App\Http\Controllers\Api\Ventas\Reportes\ConciliacionCajaControlador;
use App\Http\Controllers\Api\Ventas\Reportes\MembresiasNuevasRenovacionesControlador;
use App\Http\Controllers\Api\Ventas\Reportes\MembresiasPorVencerControlador;
use App\Http\Controllers\Api\Ventas\Reportes\CobrosMetodoPagoControlador;
use App\Http\Controllers\Api\Ventas\Reportes\ResumenComercialControlador;
use App\Http\Controllers\Api\Ventas\Reportes\VentasPeriodoControlador;
use App\Http\Controllers\Api\Ventas\Reportes\VentasResponsableControlador;
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

    Route::get('ventas-responsable', [VentasResponsableControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-VENTAS-RESPONSABLE')
        ->name('ventas.reportes.ventas-responsable');

    Route::get('membresias-nuevas-renovaciones', [MembresiasNuevasRenovacionesControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-MEMBRESIAS-NUEVAS-RENOVACIONES')
        ->name('ventas.reportes.membresias-nuevas-renovaciones');

    Route::get('membresias-por-vencer', [MembresiasPorVencerControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-MEMBRESIAS-POR-VENCER')
        ->name('ventas.reportes.membresias-por-vencer');

    Route::get('conciliacion-caja', [ConciliacionCajaControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-CONCILIACION-CAJA')
        ->name('ventas.reportes.conciliacion-caja');
});
