<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginMicrosoftRequest;
use App\Services\Seguridad\AuthService;
use App\Services\Seguridad\MenuService;
use App\Services\Seguridad\MicrosoftAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LoginMicrosoftController extends Controller
{
    public function __construct(
        private readonly MicrosoftAuthService $microsoft,
        private readonly AuthService $auth,
        private readonly MenuService $menuService,
    ) {}

    public function configuracion(): JsonResponse
    {
        return ApiResponse::exito(
            'Configuración de Microsoft consultada.',
            $this->microsoft->configuracionPublica(),
        );
    }

    public function login(LoginMicrosoftRequest $request): JsonResponse
    {
        $usuario = $this->microsoft->autenticar((string) $request->validated('access_token'));
        $sesion = $this->auth->iniciarSesionMicrosoft($usuario);

        if (! $sesion) {
            return ApiResponse::error('El usuario no está habilitado para iniciar sesión.', codigo: 403);
        }

        return ApiResponse::exito('Inicio de sesión con Microsoft correcto.', [
            'token' => $sesion['token'],
            'usuario' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'usr_tipo' => $usuario->usr_tipo,
            ],
            'menuItems' => $this->menuService->obtenerMenuUsuario($usuario),
        ]);
    }
}
