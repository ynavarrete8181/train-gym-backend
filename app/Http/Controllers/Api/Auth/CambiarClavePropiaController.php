<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\UsuarioService;
use App\Support\ApiResponse;
use App\Support\ReglasClave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CambiarClavePropiaController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarioService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:password_actual', ReglasClave::segura()],
        ]);

        $usuario = $request->user();
        if (! Hash::check($datos['password_actual'], $usuario->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }

        $this->usuarioService->cambiarClave($usuario, $datos['password']);

        return ApiResponse::exito('Contraseña actualizada. Inicia sesión nuevamente.');
    }
}
