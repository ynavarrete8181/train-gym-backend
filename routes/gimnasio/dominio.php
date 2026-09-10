<?php

use App\Http\Controllers\Api\Gimnasio\DeportistaControlador;
use App\Http\Controllers\Api\Gimnasio\EntrenadorControlador;
use App\Http\Controllers\Api\Gimnasio\MembresiaControlador;
use App\Http\Controllers\Api\Gimnasio\PlanControlador;
use App\Http\Controllers\Api\Gimnasio\ServicioAgendaControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->group(function (): void {
    Route::middleware(['base.permiso:GIMNASIO-PLANES'])->group(function (): void {
        Route::get('planes', [PlanControlador::class, 'index'])->name('dominio.planes.index');
        Route::post('planes', [PlanControlador::class, 'store'])->name('dominio.planes.store');
        Route::get('planes/{id}', [PlanControlador::class, 'show'])->name('dominio.planes.show');
        Route::put('planes/{id}', [PlanControlador::class, 'update'])->name('dominio.planes.update');
        Route::delete('planes/{id}', [PlanControlador::class, 'destroy'])->name('dominio.planes.destroy');
    });

    Route::middleware(['base.permiso:GIMNASIO-DEPORTISTAS'])->group(function (): void {
        Route::get('clientes', [DeportistaControlador::class, 'index'])->name('dominio.clientes.index');
        Route::post('clientes', [DeportistaControlador::class, 'store'])->name('dominio.clientes.store');
        Route::get('clientes/{id}', [DeportistaControlador::class, 'show'])->name('dominio.clientes.show');
        Route::put('clientes/{id}', [DeportistaControlador::class, 'update'])->name('dominio.clientes.update');
        Route::get('deportistas', [DeportistaControlador::class, 'index'])->name('dominio.deportistas.index');
        Route::post('deportistas', [DeportistaControlador::class, 'store'])->name('dominio.deportistas.store');
        Route::get('deportistas/{id}', [DeportistaControlador::class, 'show'])->name('dominio.deportistas.show');
        Route::put('deportistas/{id}', [DeportistaControlador::class, 'update'])->name('dominio.deportistas.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-MEMBRESIAS'])->group(function (): void {
        Route::get('membresias', [MembresiaControlador::class, 'index'])->name('dominio.membresias.index');
        Route::post('membresias', [MembresiaControlador::class, 'store'])->name('dominio.membresias.store');
        Route::get('membresias/{id}', [MembresiaControlador::class, 'show'])->name('dominio.membresias.show');
        Route::put('membresias/{id}', [MembresiaControlador::class, 'update'])->name('dominio.membresias.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-ENTRENADORES'])->group(function (): void {
        Route::get('entrenadores', [EntrenadorControlador::class, 'index'])->name('dominio.entrenadores.index');
        Route::post('entrenadores', [EntrenadorControlador::class, 'store'])->name('dominio.entrenadores.store');
        Route::put('entrenadores/{id}', [EntrenadorControlador::class, 'update'])->name('dominio.entrenadores.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-CATEGORIAS-SERVICIO'])->group(function (): void {
        Route::get('categorias-servicio', [ServicioAgendaControlador::class, 'categorias'])->name('dominio.categorias-servicio.index');
        Route::post('categorias-servicio', [ServicioAgendaControlador::class, 'guardarCategoria'])->name('dominio.categorias-servicio.store');
        Route::put('categorias-servicio/{id}', [ServicioAgendaControlador::class, 'guardarCategoria'])->name('dominio.categorias-servicio.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-SERVICIOS'])->group(function (): void {
        Route::get('servicios', [ServicioAgendaControlador::class, 'servicios'])->name('dominio.servicios.index');
        Route::post('servicios', [ServicioAgendaControlador::class, 'guardarServicio'])->name('dominio.servicios.store');
        Route::put('servicios/{id}', [ServicioAgendaControlador::class, 'guardarServicio'])->name('dominio.servicios.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-HORARIOS'])->group(function (): void {
        Route::get('horarios', [ServicioAgendaControlador::class, 'horarios'])->name('dominio.horarios.index');
        Route::get('horarios/{id}/detalle', [ServicioAgendaControlador::class, 'detalleHorario'])->name('dominio.horarios.detalle');
        Route::post('horarios', [ServicioAgendaControlador::class, 'guardarHorario'])->name('dominio.horarios.store');
        Route::put('horarios/{id}', [ServicioAgendaControlador::class, 'guardarHorario'])->name('dominio.horarios.update');
        Route::patch('horarios/{id}/desactivar', [ServicioAgendaControlador::class, 'desactivarHorario'])->name('dominio.horarios.desactivar');
        Route::delete('horarios/{id}', [ServicioAgendaControlador::class, 'eliminarHorario'])->name('dominio.horarios.destroy');
    });

    Route::middleware(['base.permiso:GIMNASIO-RESERVAS-DIA'])->group(function (): void {
        Route::get('reservas-dia', [ServicioAgendaControlador::class, 'reservasDia'])->name('dominio.reservas-dia.index');
        Route::post('reservas-dia', [ServicioAgendaControlador::class, 'guardarReservaDia'])->name('dominio.reservas-dia.store');
        Route::put('reservas-dia/{id}', [ServicioAgendaControlador::class, 'guardarReservaDia'])->name('dominio.reservas-dia.update');
    });
});
