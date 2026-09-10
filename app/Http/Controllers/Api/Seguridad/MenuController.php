<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\MenuService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __construct(private readonly MenuService $menuService) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::exito('Menú del usuario obtenido correctamente.', [
            'menuItems' => $this->menuService->obtenerMenuUsuario($request->user()),
        ]);
    }
}
