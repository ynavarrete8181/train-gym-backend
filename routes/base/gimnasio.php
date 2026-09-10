<?php

use App\Http\Controllers\Api\Gimnasio\DeportistaControlador;
use App\Http\Controllers\Api\Gimnasio\MembresiaControlador;
use App\Http\Controllers\Api\Gimnasio\PlanControlador;
use App\Http\Controllers\Api\Gimnasio\EntrenadorControlador;
use App\Http\Controllers\Api\Gimnasio\AsignacionEntrenadorClienteControlador;
use App\Http\Controllers\Api\Gimnasio\ServicioAgendaControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('gimnasio')->group(function (): void {
    
    // Planes
    Route::middleware(['base.permiso:GIMNASIO-PLANES'])->group(function (): void {
        Route::get('planes', [PlanControlador::class, 'index'])->name('gimnasio.planes.index');
        Route::post('planes', [PlanControlador::class, 'store'])->name('gimnasio.planes.store');
        Route::get('planes/{id}', [PlanControlador::class, 'show'])->name('gimnasio.planes.show');
        Route::put('planes/{id}', [PlanControlador::class, 'update'])->name('gimnasio.planes.update');
        Route::delete('planes/{id}', [PlanControlador::class, 'destroy'])->name('gimnasio.planes.destroy');
    });

    // Deportistas
    Route::middleware(['base.permiso:GIMNASIO-DEPORTISTAS'])->group(function (): void {
        Route::get('clientes', [DeportistaControlador::class, 'index'])->name('gimnasio.clientes.index');
        Route::post('clientes', [DeportistaControlador::class, 'store'])->name('gimnasio.clientes.store');
        Route::get('clientes/{id}', [DeportistaControlador::class, 'show'])->name('gimnasio.clientes.show');
        Route::put('clientes/{id}', [DeportistaControlador::class, 'update'])->name('gimnasio.clientes.update');
        Route::get('deportistas', [DeportistaControlador::class, 'index'])->name('gimnasio.deportistas.index');
        Route::post('deportistas', [DeportistaControlador::class, 'store'])->name('gimnasio.deportistas.store');
        Route::get('deportistas/{id}', [DeportistaControlador::class, 'show'])->name('gimnasio.deportistas.show');
        Route::put('deportistas/{id}', [DeportistaControlador::class, 'update'])->name('gimnasio.deportistas.update');
    });

    // Membresías
    Route::middleware(['base.permiso:GIMNASIO-MEMBRESIAS'])->group(function (): void {
        Route::get('membresias', [MembresiaControlador::class, 'index'])->name('gimnasio.membresias.index');
        Route::post('membresias', [MembresiaControlador::class, 'store'])->name('gimnasio.membresias.store');
        Route::get('membresias/{id}', [MembresiaControlador::class, 'show'])->name('gimnasio.membresias.show');
        Route::put('membresias/{id}', [MembresiaControlador::class, 'update'])->name('gimnasio.membresias.update');
        Route::delete('membresias/{id}', [MembresiaControlador::class, 'destroy'])->name('gimnasio.membresias.destroy');
    });

    // Entrenadores
    Route::middleware(['base.permiso:GIMNASIO-ENTRENADORES'])->group(function (): void {
        Route::get('entrenadores', [EntrenadorControlador::class, 'index'])->name('gimnasio.entrenadores.index');
        Route::post('entrenadores', [EntrenadorControlador::class, 'store'])->name('gimnasio.entrenadores.store');
        Route::put('entrenadores/{id}', [EntrenadorControlador::class, 'update'])->name('gimnasio.entrenadores.update');
        Route::get('entrenadores/{id}/turnos', [EntrenadorControlador::class, 'turnos'])->name('gimnasio.entrenadores.turnos.index');
        Route::get('entrenadores/{id}/horarios-disponibles', [EntrenadorControlador::class, 'horariosDisponibles'])->name('gimnasio.entrenadores.horarios-disponibles');
        Route::post('entrenadores/{id}/turnos', [EntrenadorControlador::class, 'asignarHorario'])->name('gimnasio.entrenadores.turnos.store');
        Route::delete('entrenadores/{id}/turnos/{turnoId}', [EntrenadorControlador::class, 'eliminarTurno'])->name('gimnasio.entrenadores.turnos.destroy');
    });

    // Asignaciones entrenador - cliente
    Route::middleware(['base.permiso:GIMNASIO-ENTRENADORES,GIMNASIO-DEPORTISTAS'])->group(function (): void {
        Route::get('asignaciones-entrenador', [AsignacionEntrenadorClienteControlador::class, 'index'])->name('gimnasio.asignaciones-entrenador.index');
        Route::post('asignaciones-entrenador', [AsignacionEntrenadorClienteControlador::class, 'store'])->name('gimnasio.asignaciones-entrenador.store');
        Route::patch('asignaciones-entrenador/{id}/finalizar', [AsignacionEntrenadorClienteControlador::class, 'finalizar'])->name('gimnasio.asignaciones-entrenador.finalizar');
    });

    // Servicios y Agenda
    Route::middleware(['base.permiso:GIMNASIO-CATEGORIAS-SERVICIO'])->group(function (): void {
        Route::get('categorias-servicio', [ServicioAgendaControlador::class, 'categorias'])->name('gimnasio.categorias-servicio.index');
        Route::post('categorias-servicio', [ServicioAgendaControlador::class, 'guardarCategoria'])->name('gimnasio.categorias-servicio.store');
        Route::put('categorias-servicio/{id}', [ServicioAgendaControlador::class, 'guardarCategoria'])->name('gimnasio.categorias-servicio.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-SERVICIOS'])->group(function (): void {
        Route::get('servicios', [ServicioAgendaControlador::class, 'servicios'])->name('gimnasio.servicios.index');
        Route::post('servicios', [ServicioAgendaControlador::class, 'guardarServicio'])->name('gimnasio.servicios.store');
        Route::put('servicios/{id}', [ServicioAgendaControlador::class, 'guardarServicio'])->name('gimnasio.servicios.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-HORARIOS'])->group(function (): void {
        Route::get('horarios', [ServicioAgendaControlador::class, 'horarios'])->name('gimnasio.horarios.index');
        Route::get('horarios/{id}/detalle', [ServicioAgendaControlador::class, 'detalleHorario'])->name('gimnasio.horarios.detalle');
        Route::post('horarios', [ServicioAgendaControlador::class, 'guardarHorario'])->name('gimnasio.horarios.store');
        Route::put('horarios/{id}', [ServicioAgendaControlador::class, 'guardarHorario'])->name('gimnasio.horarios.update');
        Route::patch('horarios/{id}/desactivar', [ServicioAgendaControlador::class, 'desactivarHorario'])->name('gimnasio.horarios.desactivar');
        Route::delete('horarios/{id}', [ServicioAgendaControlador::class, 'eliminarHorario'])->name('gimnasio.horarios.destroy');
    });

    Route::middleware(['base.permiso:GIMNASIO-RESERVAS-DIA'])->group(function (): void {
        Route::get('reservas-dia', [ServicioAgendaControlador::class, 'reservasDia'])->name('gimnasio.reservas-dia.index');
        Route::post('reservas-dia', [ServicioAgendaControlador::class, 'guardarReservaDia'])->name('gimnasio.reservas-dia.store');
        Route::put('reservas-dia/{id}', [ServicioAgendaControlador::class, 'guardarReservaDia'])->name('gimnasio.reservas-dia.update');
    });

});
