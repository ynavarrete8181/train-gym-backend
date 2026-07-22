<?php

use App\Http\Controllers\Reportes\ReportePremiumController;
use Illuminate\Support\Facades\Route;

Route::get('reportes/premium', [ReportePremiumController::class, 'index']);
