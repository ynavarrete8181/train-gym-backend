<?php

namespace App\Http\Middleware;

use App\Services\Seguridad\PermisoService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutorizarFuncionBase
{
    public function __construct(private readonly PermisoService $permisoService) {}

    public function handle(Request $request, Closure $next, string ...$codigos): Response
    {
        $usuario = $request->user();

        if (! $usuario || ! $this->permisoService->usuarioTieneAlgunaFuncion($usuario, $codigos)) {
            return ApiResponse::error('No tienes permiso para realizar esta acción.', codigo: 403);
        }

        return $next($request);
    }
}
