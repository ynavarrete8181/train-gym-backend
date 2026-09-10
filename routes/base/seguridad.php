<?php

use App\Http\Controllers\Api\Seguridad\CargaMasivaUsuarioController;
use App\Http\Controllers\Api\Seguridad\CatalogoSeguridadController;
use App\Http\Controllers\Api\Seguridad\PaginaSistemaController;
use App\Http\Controllers\Api\Seguridad\RolPermisoController;
use App\Http\Controllers\Api\Seguridad\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware('base.auth')->prefix('seguridad')->group(function (): void {
    Route::get('/roles', [CatalogoSeguridadController::class, 'roles'])
        ->middleware('base.permiso:SEGURIDAD-USUARIOS,SEGURIDAD-PERMISOS-USUARIOS,SEGURIDAD-ROLES,SEGURIDAD-SUBMENUS')
        ->name('base.seguridad.roles');

    Route::middleware('base.permiso:SEGURIDAD-ROLES')->group(function (): void {
        Route::post('/roles', [RolPermisoController::class, 'guardarRol'])->name('base.seguridad.roles.store');
        Route::put('/roles/{idRol}', [RolPermisoController::class, 'guardarRol'])->name('base.seguridad.roles.update');
        Route::patch('/roles/{idRol}/estado', [RolPermisoController::class, 'cambiarEstadoRol'])->name('base.seguridad.roles.estado');
        Route::get('/roles/{idRol}/detalle', [RolPermisoController::class, 'detalleRol'])->name('base.seguridad.roles.detalle');
        Route::post('/roles/{idRol}/funciones', [RolPermisoController::class, 'asignarFuncionRol'])->name('base.seguridad.roles.funciones.store');
        Route::delete('/roles/{idRol}/funciones/{codigo}', [RolPermisoController::class, 'quitarFuncionRol'])->name('base.seguridad.roles.funciones.destroy');
        Route::post('/roles/{idRol}/sincronizar', [RolPermisoController::class, 'sincronizarRol'])->name('base.seguridad.roles.sincronizar');
    });

    Route::get('/menus', [CatalogoSeguridadController::class, 'menus'])
        ->middleware('base.permiso:SEGURIDAD-MENUS,SEGURIDAD-SUBMENUS')
        ->name('base.seguridad.menus');

    Route::middleware('base.permiso:SEGURIDAD-MENUS')->group(function (): void {
        Route::post('/menus', [RolPermisoController::class, 'guardarMenu'])->name('base.seguridad.menus.store');
        Route::put('/menus/{idMenu}', [RolPermisoController::class, 'guardarMenu'])->name('base.seguridad.menus.update');
        Route::patch('/menus/{idMenu}/estado', [RolPermisoController::class, 'cambiarEstadoMenu'])->name('base.seguridad.menus.estado');
        Route::patch('/menus/{idMenu}/orden', [RolPermisoController::class, 'moverMenu'])->name('base.seguridad.menus.orden');
        Route::delete('/menus/{idMenu}', [RolPermisoController::class, 'eliminarMenu'])->name('base.seguridad.menus.destroy');
    });

    Route::get('/funciones-rol', [CatalogoSeguridadController::class, 'funcionesPorRol'])
        ->middleware('base.permiso:SEGURIDAD-USUARIOS,SEGURIDAD-PERMISOS-USUARIOS,SEGURIDAD-SUBMENUS')
        ->name('base.seguridad.funciones-rol');
    Route::get('/funciones-disponibles', [CatalogoSeguridadController::class, 'funcionesDisponibles'])
        ->middleware('base.permiso:SEGURIDAD-USUARIOS,SEGURIDAD-PERMISOS-USUARIOS,SEGURIDAD-ROLES,SEGURIDAD-SUBMENUS')
        ->name('base.seguridad.funciones-disponibles');

    Route::middleware('base.permiso:SEGURIDAD-SUBMENUS')->group(function (): void {
        Route::post('/funciones-rol', [RolPermisoController::class, 'guardarFuncionRol'])->name('base.seguridad.funciones-rol.store');
        Route::put('/funciones-rol/{idFuncion}', [RolPermisoController::class, 'guardarFuncionRol'])->name('base.seguridad.funciones-rol.update');
        Route::patch('/funciones-rol/{idFuncion}/estado', [RolPermisoController::class, 'cambiarEstadoFuncion'])->name('base.seguridad.funciones-rol.estado');
        Route::patch('/funciones-rol/{idFuncion}/orden', [RolPermisoController::class, 'moverFuncion'])->name('base.seguridad.funciones-rol.orden');
        Route::delete('/funciones-rol/{idFuncion}', [RolPermisoController::class, 'eliminarFuncion'])->name('base.seguridad.funciones-rol.destroy');
    });

    Route::middleware('base.permiso:SEGURIDAD-PAGINAS')->group(function (): void {
        Route::get('/paginas-sistema', [PaginaSistemaController::class, 'index'])->name('base.seguridad.paginas.index');
        Route::put('/paginas-sistema/{codigo}', [PaginaSistemaController::class, 'update'])->name('base.seguridad.paginas.update');
        Route::delete('/paginas-sistema/{codigo}', [PaginaSistemaController::class, 'destroy'])->name('base.seguridad.paginas.destroy');
    });

    Route::middleware('base.permiso:SEGURIDAD-USUARIOS,SEGURIDAD-PERMISOS-USUARIOS')->group(function (): void {
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('base.seguridad.usuarios.index');
        Route::get('/usuarios/{usuario}/funciones', [UsuarioController::class, 'funciones'])->name('base.seguridad.usuarios.funciones');
    });

    Route::middleware('base.permiso:SEGURIDAD-PERMISOS-USUARIOS')->group(function (): void {
        Route::put('/usuarios/{usuario}/accesos', [UsuarioController::class, 'guardarAccesos'])->name('base.seguridad.usuarios.accesos.guardar');
    });

    Route::middleware('base.permiso:SEGURIDAD-USUARIOS')->group(function (): void {
        Route::get('/usuarios/carga-masiva/plantilla', [CargaMasivaUsuarioController::class, 'plantilla'])->name('base.seguridad.usuarios.carga.plantilla');
        Route::post('/usuarios/carga-masiva/validar', [CargaMasivaUsuarioController::class, 'validar'])->name('base.seguridad.usuarios.carga.validar');
        Route::post('/usuarios/carga-masiva/procesar', [CargaMasivaUsuarioController::class, 'procesar'])->name('base.seguridad.usuarios.carga.procesar');
        Route::get('/usuarios/carga-masiva/recientes', [CargaMasivaUsuarioController::class, 'recientes'])->name('base.seguridad.usuarios.carga.recientes');
        Route::get('/usuarios/carga-masiva/{id}', [CargaMasivaUsuarioController::class, 'estado'])->whereNumber('id')->name('base.seguridad.usuarios.carga.estado');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('base.seguridad.usuarios.store');
        Route::get('/usuarios/{usuario}', [UsuarioController::class, 'show'])->name('base.seguridad.usuarios.show');
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('base.seguridad.usuarios.update');
        Route::patch('/usuarios/{usuario}/estado', [UsuarioController::class, 'cambiarEstado'])->name('base.seguridad.usuarios.estado');
        Route::post('/usuarios/{usuario}/restablecer-clave', [UsuarioController::class, 'restablecerClave'])->name('base.seguridad.usuarios.restablecer-clave');
        Route::put('/usuarios/{usuario}/funciones', [UsuarioController::class, 'guardarFunciones'])->name('base.seguridad.usuarios.funciones.guardar');
        Route::post('/usuarios/{usuario}/sincronizar-rol', [UsuarioController::class, 'sincronizarFuncionesRol'])->name('base.seguridad.usuarios.sincronizar-rol');
    });
});
