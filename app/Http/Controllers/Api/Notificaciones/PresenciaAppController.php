<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\UsuarioAppService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PresenciaAppController extends Controller
{
    public function __construct(private readonly UsuarioAppService $usuariosApp) {}

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'plataforma' => ['nullable', Rule::in(['android', 'ios'])],
            'version_app' => ['nullable', 'string', 'max:40'],
        ]);
        $this->usuariosApp->registrar($request->user(), $datos['plataforma'] ?? null, $datos['version_app'] ?? null);

        return ApiResponse::exito('Acceso móvil registrado.');
    }
}
