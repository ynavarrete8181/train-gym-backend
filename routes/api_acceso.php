<?php

use App\Http\Controllers\Acceso\AccesoController;
use Illuminate\Support\Facades\Route;

Route::post('acceso/validar-qr', [AccesoController::class, 'validarQr']);
