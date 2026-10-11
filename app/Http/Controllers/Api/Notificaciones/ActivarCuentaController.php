<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Services\Notificaciones\ActivacionUsuarioService;
use App\Support\ApiResponse;
use App\Support\ReglasClave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivarCuentaController extends Controller
{
    public function validar(Request $request, ActivacionUsuarioService $service): JsonResponse
    {
        $datos = $request->validate(['referencia' => ['required', 'uuid']]);

        if (! $service->enlaceDisponible($datos['referencia'])) {
            return ApiResponse::error('Este enlace ya fue utilizado, fue invalidado o ha expirado.', codigo: 410);
        }

        return ApiResponse::exito('El enlace está disponible.');
    }

    public function __invoke(Request $request, ActivacionUsuarioService $service): JsonResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string', 'min:10', 'max:64'],
            'referencia' => ['nullable', 'uuid'],
            'password' => ['required', 'string', ReglasClave::segura(), 'confirmed'],
        ]);
        try {
            $service->activar($datos['token'], $datos['password'], $datos['referencia'] ?? null);
        } catch (\RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage(), ['token' => [$exception->getMessage()]]);
        }

        return ApiResponse::exito('Contraseña establecida correctamente. Ya puedes iniciar sesión.');
    }
}
