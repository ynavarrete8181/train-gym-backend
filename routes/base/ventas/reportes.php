<?php

use App\Http\Controllers\Api\Ventas\Reportes\CarteraVencidaControlador;
use App\Http\Controllers\Api\Ventas\Reportes\ConciliacionCajaControlador;
use App\Http\Controllers\Api\Ventas\Reportes\MembresiasNuevasRenovacionesControlador;
use App\Http\Controllers\Api\Ventas\Reportes\MembresiasPorVencerControlador;
use App\Http\Controllers\Api\Ventas\Reportes\ProductosServiciosVendidosControlador;
use App\Http\Controllers\Api\Ventas\Reportes\CobrosMetodoPagoControlador;
use App\Http\Controllers\Api\Ventas\Reportes\ResumenComercialControlador;
use App\Http\Controllers\Api\Ventas\Reportes\VentasPeriodoControlador;
use App\Http\Controllers\Api\Ventas\Reportes\VentasResponsableControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('reportes')->group(function (): void {
    Route::get('resumen-comercial', [ResumenComercialControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-COMERCIAL-RESUMEN')
        ->name('ventas.reportes.resumen-comercial');

    Route::get('resumen-comercial/excel', [ResumenComercialControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-COMERCIAL-RESUMEN')
        ->name('ventas.reportes.resumen-comercial.excel');

    Route::get('cartera-vencida', [CarteraVencidaControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-CARTERA-VENCIDA')
        ->name('ventas.reportes.cartera-vencida');

    Route::get('cartera-vencida/excel', [CarteraVencidaControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-CARTERA-VENCIDA')
        ->name('ventas.reportes.cartera-vencida.excel');

    Route::get('cobros-metodo-pago', [CobrosMetodoPagoControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-COBROS-METODOS')
        ->name('ventas.reportes.cobros-metodo-pago');

    Route::get('cobros-metodo-pago/excel', [CobrosMetodoPagoControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-COBROS-METODOS')
        ->name('ventas.reportes.cobros-metodo-pago.excel');

    Route::get('ventas-periodo', [VentasPeriodoControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-VENTAS-PERIODO')
        ->name('ventas.reportes.ventas-periodo');

    Route::get('ventas-periodo/excel', [VentasPeriodoControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-VENTAS-PERIODO')
        ->name('ventas.reportes.ventas-periodo.excel');

    Route::get('ventas-responsable', [VentasResponsableControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-VENTAS-RESPONSABLE')
        ->name('ventas.reportes.ventas-responsable');

    Route::get('ventas-responsable/excel', [VentasResponsableControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-VENTAS-RESPONSABLE')
        ->name('ventas.reportes.ventas-responsable.excel');

    Route::get('membresias-nuevas-renovaciones', [MembresiasNuevasRenovacionesControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-MEMBRESIAS-NUEVAS-RENOVACIONES')
        ->name('ventas.reportes.membresias-nuevas-renovaciones');

    Route::get('membresias-nuevas-renovaciones/excel', [MembresiasNuevasRenovacionesControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-MEMBRESIAS-NUEVAS-RENOVACIONES')
        ->name('ventas.reportes.membresias-nuevas-renovaciones.excel');

    Route::get('membresias-por-vencer', [MembresiasPorVencerControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-MEMBRESIAS-POR-VENCER')
        ->name('ventas.reportes.membresias-por-vencer');

    Route::get('membresias-por-vencer/excel', [MembresiasPorVencerControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-MEMBRESIAS-POR-VENCER')
        ->name('ventas.reportes.membresias-por-vencer.excel');

    Route::get('conciliacion-caja', [ConciliacionCajaControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-CONCILIACION-CAJA')
        ->name('ventas.reportes.conciliacion-caja');

    Route::get('conciliacion-caja/excel', [ConciliacionCajaControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-CONCILIACION-CAJA')
        ->name('ventas.reportes.conciliacion-caja.excel');

    Route::get('productos-servicios-vendidos', [ProductosServiciosVendidosControlador::class, 'index'])
        ->middleware('base.permiso:REPORTES-PRODUCTOS-SERVICIOS')
        ->name('ventas.reportes.productos-servicios-vendidos');

    Route::get('productos-servicios-vendidos/excel', [ProductosServiciosVendidosControlador::class, 'excel'])
        ->middleware('base.permiso:REPORTES-PRODUCTOS-SERVICIOS')
        ->name('ventas.reportes.productos-servicios-vendidos.excel');
});
