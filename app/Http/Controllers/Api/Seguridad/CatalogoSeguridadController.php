<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\CatalogoSeguridadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogoSeguridadController extends Controller
{
    public function __construct(private readonly CatalogoSeguridadService $catalogoService) {}

    public function roles(): JsonResponse
    {
        return ApiResponse::exito('Roles consultados correctamente.', $this->catalogoService->roles());
    }

    public function menus(): JsonResponse
    {
        return ApiResponse::exito('Menús consultados correctamente.', $this->catalogoService->menus());
    }

    public function funcionesPorRol(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'rol' => ['required', 'integer'],
        ]);

        $existe = DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $datos['rol'])
            ->where('activo', true)
            ->exists();

        if (! $existe) {
            throw ValidationException::withMessages([
                'rol' => 'El rol seleccionado no existe o está inactivo.',
            ]);
        }

        return ApiResponse::exito(
            'Funciones del rol consultadas correctamente.',
            $this->catalogoService->funcionesAgrupadasPorRol((int) $datos['rol']),
        );
    }

    public function funcionesDisponibles(): JsonResponse
    {
        return ApiResponse::exito('Funciones disponibles consultadas correctamente.', $this->catalogoService->funcionesDisponibles());
    }
}
