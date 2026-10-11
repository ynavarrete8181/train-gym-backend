<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\AuthService;
use App\Services\Seguridad\MenuService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly MenuService $menuService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $login = $this->authService->intentarLogin($datos['email'], $datos['password']);

        if (! $login) {
            return ApiResponse::error('Credenciales incorrectas o usuario inactivo.', codigo: 401);
        }

        return ApiResponse::exito('Inicio de sesión correcto.', [
            'token' => $login['token'],
            'usuario' => [
                'id' => $login['usuario']->id,
                'name' => $login['usuario']->name,
                'email' => $login['usuario']->email,
                'usr_tipo' => $login['usuario']->usr_tipo,
            ],
            'menuItems' => $this->menuService->obtenerMenuUsuario($login['usuario']),
        ]);
    }
}
