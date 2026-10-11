<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Gimnasio\PlanControlador;
use Illuminate\Http\Request;

Route::put('/test-planes/{id}', [PlanControlador::class, 'update']);
