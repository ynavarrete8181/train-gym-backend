<?php

namespace App\Http\Controllers\Api\Base;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EstadoSistemaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::exito(
            mensaje: 'El API del Revive está disponible.',
            datos: [
                'sistema' => config('base.nombre'),
                'version' => config('base.version'),
                'entorno' => app()->environment(),
            ],
        );
    }
}
