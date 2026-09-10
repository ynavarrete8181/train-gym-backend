<?php

use App\Http\Controllers\Api\Seguridad\MenuController;
use Illuminate\Support\Facades\Route;

Route::middleware('base.auth')->get('/menu', MenuController::class)->name('base.menu');
