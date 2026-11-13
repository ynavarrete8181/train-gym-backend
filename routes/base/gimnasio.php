<?php

use App\Http\Controllers\Api\Gimnasio\DeportistaControlador;
use App\Http\Controllers\Api\Gimnasio\TutorControlador;
use App\Http\Controllers\Api\Geografia\CatalogoGeograficoController;
use App\Http\Controllers\Api\Gimnasio\FichaInicialControlador;
use App\Http\Controllers\Api\Gimnasio\ValoracionDeportistaControlador;
use App\Http\Controllers\Api\Gimnasio\FotografiaValoracionControlador;
use App\Http\Controllers\Api\Gimnasio\MembresiaControlador;
use App\Http\Controllers\Api\Gimnasio\CicloVidaMembresiaControlador;
use App\Http\Controllers\Api\Gimnasio\MotivoCicloVidaControlador;
use App\Http\Controllers\Api\Gimnasio\DescuentoDeportistaControlador;
use App\Http\Controllers\Api\Gimnasio\PlanControlador;
use App\Http\Controllers\Api\Gimnasio\EntrenadorControlador;
use App\Http\Controllers\Api\Gimnasio\HorarioEntrenadorControlador;
use App\Http\Controllers\Api\Gimnasio\AsignacionEntrenadorClienteControlador;
use App\Http\Controllers\Api\Gimnasio\ClienteCatalogoControlador;
use App\Http\Controllers\Api\Gimnasio\ServicioAgendaControlador;
use App\Http\Controllers\Api\Gimnasio\MiTrabajoEntrenadorControlador;
use App\Http\Controllers\Api\Gimnasio\AgendaConfiguracionControlador;
use App\Http\Controllers\Api\Gimnasio\AgendaOperacionControlador;
use Illuminate\Support\Facades\Route;

Route::middleware(['base.auth'])->prefix('gimnasio')->group(function (): void {
    Route::middleware('base.permiso:GIMNASIO-DEPORTISTAS,GIMNASIO-ENTRENADORES')->group(function (): void {
        Route::get('geografia/provincias', [CatalogoGeograficoController::class, 'provincias']);
        Route::get('geografia/cantones', [CatalogoGeograficoController::class, 'cantones']);
        Route::get('geografia/parroquias', [CatalogoGeograficoController::class, 'parroquias']);
    });


    // Trabajo propio del entrenador: nunca recibe ids externos, siempre se resuelve desde el usuario autenticado.
    Route::get('mi-entrenamiento/deportistas', [MiTrabajoEntrenadorControlador::class, 'deportistas'])
        ->middleware('base.permiso:ENTRENADOR-MIS-DEPORTISTAS')
        ->name('gimnasio.mi-entrenamiento.deportistas');
    Route::get('mi-entrenamiento/agenda', [MiTrabajoEntrenadorControlador::class, 'agenda'])
        ->middleware('base.permiso:ENTRENADOR-MI-AGENDA')
        ->name('gimnasio.mi-entrenamiento.agenda');

    // Planes: Clientes y Membresías pueden consultar el catálogo; solo GIMNASIO-PLANES lo administra.
    Route::get('planes/catalogos/servicios', [PlanControlador::class, 'serviciosCatalogo'])
        ->middleware('base.permiso:GIMNASIO-PLANES,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.planes.servicios.catalogo');
    Route::get('planes', [PlanControlador::class, 'index'])
        ->middleware('base.permiso:GIMNASIO-PLANES,GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.planes.index');
    Route::get('planes/{id}', [PlanControlador::class, 'show'])
        ->middleware('base.permiso:GIMNASIO-PLANES,GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.planes.show');
    Route::middleware(['base.permiso:GIMNASIO-PLANES'])->group(function (): void {
        Route::post('planes', [PlanControlador::class, 'store'])->name('gimnasio.planes.store');
        Route::put('planes/{id}', [PlanControlador::class, 'update'])->name('gimnasio.planes.update');
        Route::get('planes/{id}/modalidades', [PlanControlador::class, 'modalidades'])->name('gimnasio.planes.modalidades.index');
        Route::get('planes/{id}/modalidades/{modalidadId}/tarifas', [PlanControlador::class, 'historialTarifas'])->whereNumber('modalidadId')->name('gimnasio.planes.modalidades.tarifas');
        Route::post('planes/{id}/modalidades', [PlanControlador::class, 'storeModalidad'])->name('gimnasio.planes.modalidades.store');
        Route::put('planes/{id}/modalidades/{modalidadId}', [PlanControlador::class, 'updateModalidad'])->name('gimnasio.planes.modalidades.update');
    });

    // Clientes / deportistas: rutas estáticas antes de {id} para evitar que Laravel
    // interprete "usuarios-disponibles" o "capacidades" como un identificador.
    Route::middleware(['base.permiso:GIMNASIO-DEPORTISTAS'])->group(function (): void {
        Route::get('clientes/capacidades', [ClienteCatalogoControlador::class, 'capacidades'])
            ->name('gimnasio.clientes.capacidades');
        Route::get('clientes/{id}/diagnostico-identidad', [DeportistaControlador::class, 'diagnosticoIdentidad'])
            ->whereNumber('id')
            ->name('gimnasio.clientes.diagnostico-identidad');
        Route::get('clientes/usuarios-disponibles', [ClienteCatalogoControlador::class, 'usuariosDeportistasDisponibles'])
            ->name('gimnasio.clientes.usuarios-disponibles');
        Route::get('tutores/buscar', [TutorControlador::class, 'buscar'])->name('gimnasio.tutores.buscar');
        Route::post('tutores', [TutorControlador::class, 'store'])->name('gimnasio.tutores.store');
        Route::get('tutores/{id}', [TutorControlador::class, 'show'])->whereNumber('id')->name('gimnasio.tutores.show');
        Route::put('tutores/{id}', [TutorControlador::class, 'update'])->whereNumber('id')->name('gimnasio.tutores.update');
        Route::post('clientes/{id}/tutor/quitar', [TutorControlador::class, 'quitarDeCliente'])->whereNumber('id')->name('gimnasio.clientes.tutor.quitar');
        Route::post('clientes', [DeportistaControlador::class, 'store'])->name('gimnasio.clientes.store');
        Route::put('clientes/{id}', [DeportistaControlador::class, 'update'])->name('gimnasio.clientes.update');
        Route::post('deportistas', [DeportistaControlador::class, 'store'])->name('gimnasio.deportistas.store');
        Route::put('deportistas/{id}', [DeportistaControlador::class, 'update'])->name('gimnasio.deportistas.update');
    });

    Route::get('clientes', [DeportistaControlador::class, 'index'])
        ->middleware('base.permiso:GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.clientes.index');
    Route::get('clientes/{id}', [DeportistaControlador::class, 'show'])
        ->whereNumber('id')
        ->middleware('base.permiso:GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.clientes.show');
    Route::get('deportistas', [DeportistaControlador::class, 'index'])
        ->middleware('base.permiso:GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.deportistas.index');
    Route::get('deportistas/{id}', [DeportistaControlador::class, 'show'])
        ->whereNumber('id')
        ->middleware('base.permiso:GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.deportistas.show');

    // Ficha inicial: el controlador valida ADMINISTRADOR o ENTRENADOR asignado; acceso autenticado.
    Route::get('clientes/{id}/ficha-inicial', [FichaInicialControlador::class, 'mostrar'])->whereNumber('id');
    Route::put('clientes/{id}/ficha-inicial', [FichaInicialControlador::class, 'guardar'])->whereNumber('id');

    Route::get('clientes/{id}/fotos-valoracion', [FotografiaValoracionControlador::class, 'index'])->whereNumber('id');
    Route::post('clientes/{id}/fotos-valoracion', [FotografiaValoracionControlador::class, 'guardar'])->whereNumber('id');
    Route::get('clientes/{id}/fotos-valoracion/{foto}', [FotografiaValoracionControlador::class, 'mostrar'])->whereNumber('id')->whereNumber('foto');
    Route::get('clientes/{id}/reporte-valoraciones', [ValoracionDeportistaControlador::class, 'reporteValoraciones'])->whereNumber('id');
    Route::get('clientes/{id}/progreso-informe', [ValoracionDeportistaControlador::class, 'progreso'])->whereNumber('id');
    Route::get('clientes/{id}/valoraciones/controles', [ValoracionDeportistaControlador::class, 'controlesDeportista'])->whereNumber('id');
    Route::get('clientes/{id}/valoraciones', [ValoracionDeportistaControlador::class, 'listar'])->whereNumber('id');
    Route::post('clientes/{id}/valoraciones', [ValoracionDeportistaControlador::class, 'crear'])->whereNumber('id');
    Route::put('clientes/{id}/valoraciones/{registro}', [ValoracionDeportistaControlador::class, 'actualizar'])->whereNumber('id')->whereNumber('registro');
    Route::patch('clientes/{id}/valoraciones/{registro}/estado', [ValoracionDeportistaControlador::class, 'cambiarEstado'])->whereNumber('id')->whereNumber('registro');
    Route::get('valoraciones/controles-pendientes', [ValoracionDeportistaControlador::class, 'controles']);

    Route::get('membresias/ciclo-vida/motivos', [MotivoCicloVidaControlador::class, 'index'])
        ->middleware('base.permiso:GIMNASIO-MEMBRESIAS,GIMNASIO-PLANES');
    Route::post('membresias/ciclo-vida/motivos', [MotivoCicloVidaControlador::class, 'store'])
        ->middleware('base.permiso:GIMNASIO-PLANES');
    Route::put('membresias/ciclo-vida/motivos/{id}', [MotivoCicloVidaControlador::class, 'update'])
        ->whereNumber('id')->middleware('base.permiso:GIMNASIO-PLANES');

    // Membresías
    Route::middleware(['base.permiso:GIMNASIO-MEMBRESIAS'])->group(function (): void {
        Route::get('membresias', [MembresiaControlador::class, 'index'])->name('gimnasio.membresias.index');
        Route::post('membresias', [MembresiaControlador::class, 'store'])->name('gimnasio.membresias.store');
        Route::get('membresias/{id}', [MembresiaControlador::class, 'show'])->name('gimnasio.membresias.show');
        Route::put('membresias/{id}', [MembresiaControlador::class, 'update'])->name('gimnasio.membresias.update');
        Route::get('membresias/{id}/ciclo-vida', [CicloVidaMembresiaControlador::class, 'mostrar'])->whereNumber('id')->name('gimnasio.membresias.ciclo-vida');
        Route::post('membresias/{id}/congelacion/preview', [CicloVidaMembresiaControlador::class, 'previsualizar'])->whereNumber('id')->name('gimnasio.membresias.congelacion.preview');
        Route::post('membresias/{id}/congelacion/programar', [CicloVidaMembresiaControlador::class, 'programar'])->whereNumber('id')->name('gimnasio.membresias.congelacion.programar');
        Route::post('membresias/{id}/congelacion/{suspensionId}/preview-edicion', [CicloVidaMembresiaControlador::class, 'previsualizarEdicion'])->whereNumber('id')->whereNumber('suspensionId')->name('gimnasio.membresias.congelacion.preview-edicion');
        Route::put('membresias/{id}/congelacion/{suspensionId}/programacion', [CicloVidaMembresiaControlador::class, 'actualizarProgramada'])->whereNumber('id')->whereNumber('suspensionId')->name('gimnasio.membresias.congelacion.programacion.update');
        Route::post('membresias/{id}/congelacion/{suspensionId}/cancelar', [CicloVidaMembresiaControlador::class, 'cancelarProgramada'])->whereNumber('id')->whereNumber('suspensionId')->name('gimnasio.membresias.congelacion.cancelar');
        Route::post('membresias/{id}/congelacion/{suspensionId}/preview-prorroga', [CicloVidaMembresiaControlador::class, 'previsualizarProrroga'])->whereNumber('id')->whereNumber('suspensionId');
        Route::put('membresias/{id}/congelacion/{suspensionId}/prorroga', [CicloVidaMembresiaControlador::class, 'prorrogar'])->whereNumber('id')->whereNumber('suspensionId');
        Route::post('membresias/{id}/congelacion', [CicloVidaMembresiaControlador::class, 'congelar'])->whereNumber('id')->name('gimnasio.membresias.congelacion');
        Route::get('membresias/{id}/reactivacion/preview', [CicloVidaMembresiaControlador::class, 'previsualizarReactivacion'])->whereNumber('id')->name('gimnasio.membresias.reactivacion.preview');
        Route::post('membresias/{id}/reactivacion', [CicloVidaMembresiaControlador::class, 'reactivar'])->whereNumber('id')->name('gimnasio.membresias.reactivacion');
        Route::get('membresias/{id}/cancelacion/preview', [CicloVidaMembresiaControlador::class, 'previsualizarCancelacion'])->whereNumber('id');
        Route::post('membresias/{id}/cancelacion', [CicloVidaMembresiaControlador::class, 'cancelarContrato'])->whereNumber('id');
        Route::put('membresias/{id}/vigencia', [CicloVidaMembresiaControlador::class, 'editarVigencia'])->whereNumber('id');
        Route::put('membresias/{id}/precio', [CicloVidaMembresiaControlador::class, 'cambiarPrecio'])->whereNumber('id');
        Route::put('membresias/{id}/sedes', [CicloVidaMembresiaControlador::class, 'actualizarSedes'])->whereNumber('id');
        Route::put('membresias/{id}/configuracion', [CicloVidaMembresiaControlador::class, 'actualizarConfiguracion'])->whereNumber('id');
        Route::get('membresias/{id}/resumen-pdf', [CicloVidaMembresiaControlador::class, 'resumenPdf'])->whereNumber('id');
        Route::get('membresias/{id}/descuentos', [CicloVidaMembresiaControlador::class, 'descuentos'])->whereNumber('id');
        Route::post('membresias/{id}/descuentos', [CicloVidaMembresiaControlador::class, 'registrarDescuento'])->whereNumber('id');
        Route::put('membresias/{id}/descuentos/{descuentoId}', [CicloVidaMembresiaControlador::class, 'actualizarDescuento'])->whereNumber('id')->whereNumber('descuentoId');
        Route::post('membresias/{id}/descuentos/{descuentoId}/finalizar', [CicloVidaMembresiaControlador::class, 'finalizarDescuento'])->whereNumber('id')->whereNumber('descuentoId');
        Route::get('membresias/{id}/reapertura/preview', [CicloVidaMembresiaControlador::class, 'previsualizarReapertura'])->whereNumber('id');
        Route::post('membresias/{id}/reapertura', [CicloVidaMembresiaControlador::class, 'reabrirContrato'])->whereNumber('id');
        Route::get('membresias/{id}/expediente', [MembresiaControlador::class, 'expediente'])->whereNumber('id')->name('gimnasio.membresias.expediente');
        Route::get('membresias/{id}/historial-integral', [MembresiaControlador::class, 'historialIntegral'])->whereNumber('id')->name('gimnasio.membresias.historial-integral');
        Route::get('membresias/{id}/renovacion-programada/preview', [MembresiaControlador::class, 'previsualizarRenovacionProgramada'])->whereNumber('id')->name('gimnasio.membresias.renovacion-programada.preview');
        Route::post('membresias/{id}/renovacion-programada', [MembresiaControlador::class, 'registrarRenovacionProgramada'])->whereNumber('id')->name('gimnasio.membresias.renovacion-programada.store');
        Route::post('membresias/{id}/renovar', [MembresiaControlador::class, 'renovar'])->name('gimnasio.membresias.renovar');
    });

    // Beneficios personalizados: solo administradores de planes.
    Route::middleware(['base.permiso:GIMNASIO-PLANES'])->group(function (): void {
        Route::get('descuentos-deportistas', [DescuentoDeportistaControlador::class, 'index'])->name('gimnasio.descuentos-deportistas.index');
        Route::post('descuentos-deportistas', [DescuentoDeportistaControlador::class, 'store'])->name('gimnasio.descuentos-deportistas.store');
        Route::put('descuentos-deportistas/{id}', [DescuentoDeportistaControlador::class, 'update'])->whereNumber('id')->name('gimnasio.descuentos-deportistas.update');
    });

    // Entrenadores: Clientes puede consultar asignación/horario sin adquirir permisos de administración.
    Route::get('entrenadores', [EntrenadorControlador::class, 'index'])
        ->middleware('base.permiso:GIMNASIO-ENTRENADORES,GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.entrenadores.index');
    Route::get('entrenadores/{id}/turnos', [EntrenadorControlador::class, 'turnos'])
        ->middleware('base.permiso:GIMNASIO-ENTRENADORES,GIMNASIO-DEPORTISTAS,GIMNASIO-MEMBRESIAS')
        ->name('gimnasio.entrenadores.turnos.index');
    Route::get('entrenadores/{id}/horarios-disponibles', [EntrenadorControlador::class, 'horariosDisponibles'])
        ->middleware('base.permiso:GIMNASIO-ENTRENADORES,GIMNASIO-DEPORTISTAS')
        ->name('gimnasio.entrenadores.horarios-disponibles');
    Route::get('entrenadores/catalogos/servicios', [EntrenadorControlador::class, 'serviciosCatalogo'])
        ->middleware('base.permiso:GIMNASIO-ENTRENADORES,GIMNASIO-SERVICIOS,GIMNASIO-DISPONIBILIDAD')
        ->name('gimnasio.entrenadores.servicios.catalogo');

    Route::middleware(['base.permiso:GIMNASIO-ENTRENADORES'])->group(function (): void {
        Route::get('entrenadores/{id}/configuracion', [EntrenadorControlador::class, 'configuracion'])->name('gimnasio.entrenadores.configuracion');
        Route::post('entrenadores', [EntrenadorControlador::class, 'store'])->name('gimnasio.entrenadores.store');
        Route::put('entrenadores/{id}', [EntrenadorControlador::class, 'update'])->name('gimnasio.entrenadores.update');
        Route::post('entrenadores/{id}/turnos', [EntrenadorControlador::class, 'asignarHorario'])->name('gimnasio.entrenadores.turnos.store');
        Route::delete('entrenadores/{id}/turnos/{turnoId}', [EntrenadorControlador::class, 'eliminarTurno'])->name('gimnasio.entrenadores.turnos.destroy');
        Route::get('entrenadores/horarios/catalogos', [HorarioEntrenadorControlador::class, 'catalogos'])->name('gimnasio.entrenadores.horarios.catalogos');
        Route::get('entrenadores/{entrenadorId}/horarios', [HorarioEntrenadorControlador::class, 'index'])->name('gimnasio.entrenadores.horarios.index');
        Route::post('entrenadores/{entrenadorId}/horarios', [HorarioEntrenadorControlador::class, 'store'])->name('gimnasio.entrenadores.horarios.store');
        Route::put('entrenadores/{entrenadorId}/horarios/{id}', [HorarioEntrenadorControlador::class, 'update'])->name('gimnasio.entrenadores.horarios.update');
    });

    // Asignaciones entrenador - cliente: consulta desde la ficha, escritura solo para quien administra entrenadores.
    Route::get('asignaciones-entrenador', [AsignacionEntrenadorClienteControlador::class, 'index'])
        ->middleware('base.permiso:GIMNASIO-ENTRENADORES,GIMNASIO-DEPORTISTAS')
        ->name('gimnasio.asignaciones-entrenador.index');
    Route::middleware(['base.permiso:GIMNASIO-ENTRENADORES'])->group(function (): void {
        Route::post('asignaciones-entrenador', [AsignacionEntrenadorClienteControlador::class, 'store'])->name('gimnasio.asignaciones-entrenador.store');
        Route::patch('asignaciones-entrenador/{id}/observaciones', [AsignacionEntrenadorClienteControlador::class, 'actualizarObservaciones'])->name('gimnasio.asignaciones-entrenador.observaciones');
        Route::patch('asignaciones-entrenador/{id}/inactivar', [AsignacionEntrenadorClienteControlador::class, 'inactivar'])->name('gimnasio.asignaciones-entrenador.inactivar');
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
        Route::get('agenda/jornadas', [AgendaConfiguracionControlador::class, 'jornadas'])->name('gimnasio.agenda.jornadas.index');
        Route::post('agenda/jornadas', [AgendaConfiguracionControlador::class, 'guardarJornada'])->name('gimnasio.agenda.jornadas.store');
        Route::put('agenda/jornadas/{id}', [AgendaConfiguracionControlador::class, 'guardarJornada'])->name('gimnasio.agenda.jornadas.update');

        Route::get('horarios', [ServicioAgendaControlador::class, 'horarios'])->name('gimnasio.horarios.index');
        Route::get('horarios/{id}/detalle', [ServicioAgendaControlador::class, 'detalleHorario'])->name('gimnasio.horarios.detalle');
        Route::post('horarios', [ServicioAgendaControlador::class, 'guardarHorario'])->name('gimnasio.horarios.store');
        Route::put('horarios/{id}', [ServicioAgendaControlador::class, 'guardarHorario'])->name('gimnasio.horarios.update');
        Route::patch('horarios/{id}/desactivar', [ServicioAgendaControlador::class, 'desactivarHorario'])->name('gimnasio.horarios.desactivar');
        Route::delete('horarios/{id}', [ServicioAgendaControlador::class, 'eliminarHorario'])->name('gimnasio.horarios.destroy');
    });

    Route::middleware(['base.permiso:GIMNASIO-RECESOS'])->group(function (): void {
        Route::get('agenda/recesos', [AgendaConfiguracionControlador::class, 'recesos'])->name('gimnasio.agenda.recesos.index');
        Route::post('agenda/recesos', [AgendaConfiguracionControlador::class, 'guardarReceso'])->name('gimnasio.agenda.recesos.store');
        Route::put('agenda/recesos/{id}', [AgendaConfiguracionControlador::class, 'guardarReceso'])->name('gimnasio.agenda.recesos.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-ASIGNACION-HORARIOS'])->group(function (): void {
        Route::get('agenda/asignaciones-horario', [AgendaConfiguracionControlador::class, 'asignaciones'])->name('gimnasio.agenda.asignaciones.index');
        Route::get('agenda/asignaciones-horario/catalogos', [AgendaConfiguracionControlador::class, 'catalogosAsignacion'])->name('gimnasio.agenda.asignaciones.catalogos');
        Route::get('agenda/asignaciones-horario/entrenadores', [AgendaConfiguracionControlador::class, 'buscarEntrenadores'])->name('gimnasio.agenda.asignaciones.entrenadores');
        Route::post('agenda/asignaciones-horario', [AgendaConfiguracionControlador::class, 'guardarAsignacion'])->name('gimnasio.agenda.asignaciones.store');
        Route::put('agenda/asignaciones-horario/{id}', [AgendaConfiguracionControlador::class, 'guardarAsignacion'])->name('gimnasio.agenda.asignaciones.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-DISPONIBILIDAD'])->group(function (): void {
        Route::get('agenda/catalogos-operacion', [AgendaOperacionControlador::class, 'catalogos'])->name('gimnasio.agenda.operacion.catalogos');
        Route::get('agenda/entrenadores-disponibilidad', [AgendaConfiguracionControlador::class, 'entrenadoresDisponibilidad'])->name('gimnasio.agenda.entrenadores-disponibilidad');
        Route::get('agenda/servicios-disponibles', [AgendaOperacionControlador::class, 'serviciosDisponibles'])->name('gimnasio.agenda.servicios-disponibles');
        Route::get('agenda/disponibilidad', [AgendaOperacionControlador::class, 'disponibilidad'])->name('gimnasio.agenda.disponibilidad');
    });

    Route::middleware(['base.permiso:GIMNASIO-EXCEPCIONES-HORARIO'])->group(function (): void {
        Route::get('agenda/excepciones', [AgendaOperacionControlador::class, 'excepciones'])->name('gimnasio.agenda.excepciones.index');
        Route::post('agenda/excepciones', [AgendaOperacionControlador::class, 'guardarExcepcion'])->name('gimnasio.agenda.excepciones.store');
        Route::put('agenda/excepciones/{id}', [AgendaOperacionControlador::class, 'guardarExcepcion'])->name('gimnasio.agenda.excepciones.update');
    });

    Route::middleware(['base.permiso:GIMNASIO-RESERVAS-DIA'])->group(function (): void {
        Route::get('agenda/reservas', [AgendaOperacionControlador::class, 'reservas'])->name('gimnasio.agenda.reservas.index');

        Route::get('reservas-dia', [ServicioAgendaControlador::class, 'reservasDia'])->name('gimnasio.reservas-dia.index');
        Route::post('reservas-dia', [ServicioAgendaControlador::class, 'guardarReservaDia'])->name('gimnasio.reservas-dia.store');
        Route::put('reservas-dia/{id}', [ServicioAgendaControlador::class, 'guardarReservaDia'])->name('gimnasio.reservas-dia.update');
    });

});
