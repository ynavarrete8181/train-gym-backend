<?php

use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\Seguridad\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::get('auditoria', [AuditController::class, 'index']);
Route::get('auditoria/resumen', [AuditController::class, 'summary']);
Route::get('seguridad/usuarios', [UsuarioController::class, 'index']);
Route::get('seguridad/usuarios/catalogos', [UsuarioController::class, 'catalogos']);
Route::get('seguridad/usuarios/{id}', [UsuarioController::class, 'show']);
Route::post('seguridad/usuarios', [UsuarioController::class, 'store']);
Route::put('seguridad/usuarios/{id}', [UsuarioController::class, 'update']);
Route::put('seguridad/usuarios/{id}/accesos', [UsuarioController::class, 'updateAccess']);
Route::post('seguridad/usuarios/{id}/reenviar-credenciales', [UsuarioController::class, 'resendCredentials']);
Route::post('seguridad/usuarios/{id}/estado', [UsuarioController::class, 'changeStatus']);
Route::get('seguridad/roles', [UsuarioController::class, 'roles']);
