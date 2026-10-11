<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Seguridad\UsuarioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CambiarClaveController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarioService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                'min:8',
                'regex:/[A-ZÁÉÍÓÚÑ]/u',
                'regex:/[a-záéíóúñ]/u',
                'regex:/\d/',
                'regex:/[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9\s]/u',
            ],
        ]);

        /** @var User|null $usuario */
        $usuario = $request->user();

        if (!$usuario || !Hash::check($datos['password_actual'], $usuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => 'La contraseña actual no es correcta.',
            ]);
        }

        if (Hash::check($datos['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'password' => 'La nueva contraseña debe ser diferente de la contraseña actual.',
            ]);
        }

        $this->usuarioService->cambiarClave($usuario, $datos['password']);

        return ApiResponse::exito('Contraseña actualizada correctamente. Debes iniciar sesión nuevamente.');
    }
}
