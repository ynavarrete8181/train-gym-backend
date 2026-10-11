<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\MenuService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioActualController extends Controller
{
    public function __construct(private readonly MenuService $menuService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $rol = \Illuminate\Support\Facades\DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $usuario->usr_tipo)
            ->value('role');

        return ApiResponse::exito('Usuario autenticado obtenido correctamente.', [
            'usuario' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'email' => $usuario->email,
                'usr_tipo' => $usuario->usr_tipo,
                'rol_nombre' => $rol,
            ],
            'menuItems' => $this->menuService->obtenerMenuUsuario($usuario),
        ]);
    }
}
