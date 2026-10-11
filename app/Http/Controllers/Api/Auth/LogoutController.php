<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authService->cerrarSesion((string) $request->bearerToken());

        return ApiResponse::exito('Sesión cerrada correctamente.');
    }
}
