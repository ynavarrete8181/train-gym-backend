<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Services\Seguridad\RolPermisoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolPermisoController extends Controller
{
    public function __construct(private readonly RolPermisoService $rolPermisoService) {}

    public function guardarRol(Request $request, ?int $idRol = null): JsonResponse
    {
        $datos = $request->validate([
            'role' => ['required', 'string', 'max:160'],
            'activo' => ['required', 'boolean'],
        ]);

        $existe = DB::table('seguridad.cpu_userrole')
            ->whereRaw('UPPER(role) = ?', [mb_strtoupper(trim($datos['role']))])
            ->when($idRol, fn ($query) => $query->where('id_userrole', '<>', $idRol))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'role' => 'Ya existe un rol con ese nombre.',
            ]);
        }

        $rol = $idRol
            ? $this->rolPermisoService->actualizarRol($idRol, $datos)
            : $this->rolPermisoService->crearRol($datos);

        return ApiResponse::exito($idRol ? 'Rol actualizado correctamente.' : 'Rol creado correctamente.', $rol);
    }

    public function guardarMenu(Request $request, ?int $idMenu = null): JsonResponse
    {
        $datos = $request->validate([
            'menu' => ['required', 'string', 'max:160'],
            'icono' => ['nullable', 'string', 'max:120'],
            'activo' => ['required', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $menu = $idMenu
            ? $this->rolPermisoService->actualizarMenu($idMenu, $datos)
            : $this->rolPermisoService->crearMenu($datos);

        return ApiResponse::exito($idMenu ? 'Menú actualizado correctamente.' : 'Menú creado correctamente.', $menu);
    }

    public function cambiarEstadoRol(Request $request, int $idRol): JsonResponse
    {
        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $rol = $this->rolPermisoService->cambiarEstadoRol($idRol, $datos['activo']);

        return ApiResponse::exito('Estado del rol actualizado correctamente.', $rol);
    }

    public function cambiarEstadoMenu(Request $request, int $idMenu): JsonResponse
    {
        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $menu = $this->rolPermisoService->cambiarEstadoMenu($idMenu, $datos['activo']);

        return ApiResponse::exito('Estado del menú actualizado correctamente.', $menu);
    }

    public function moverMenu(Request $request, int $idMenu): JsonResponse
    {
        $datos = $request->validate([
            'direccion' => ['required', 'in:arriba,abajo'],
        ]);

        $menu = $this->rolPermisoService->moverMenu($idMenu, $datos['direccion']);

        return ApiResponse::exito('Orden del menú actualizado correctamente.', $menu);
    }

    public function eliminarMenu(int $idMenu): JsonResponse
    {
        $this->rolPermisoService->eliminarMenu($idMenu);

        return ApiResponse::exito('Menú eliminado correctamente.');
    }

    public function detalleRol(int $idRol): JsonResponse
    {
        return ApiResponse::exito('Detalle de rol consultado correctamente.', $this->rolPermisoService->detalleRol($idRol));
    }

    public function asignarFuncionRol(Request $request, int $idRol): JsonResponse
    {
        $datos = $request->validate([
            'id_menu' => ['required', 'string', 'max:120'],
        ]);

        $funcion = $this->rolPermisoService->asignarFuncionExistente($idRol, $datos['id_menu']);

        return ApiResponse::exito('Opción asignada correctamente.', $funcion);
    }

    public function quitarFuncionRol(int $idRol, string $codigo): JsonResponse
    {
        $this->rolPermisoService->quitarFuncionRol($idRol, $codigo);

        return ApiResponse::exito('Opción retirada correctamente.');
    }

    public function guardarFuncionRol(Request $request, ?int $idFuncion = null): JsonResponse
    {
        $datos = $request->validate([
            'id_userrole' => [$idFuncion ? 'required' : 'nullable', 'integer'],
            'id_userroles' => [$idFuncion ? 'nullable' : 'required', 'array', 'min:1'],
            'id_userroles.*' => ['integer', 'distinct'],
            'id_usermenu' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:180'],
            'icono' => ['nullable', 'string', 'max:120'],
            'accion' => ['nullable', 'string', 'max:220'],
            'id_menu' => ['nullable', 'string', 'max:120'],
            'activo' => ['required', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $funcion = $idFuncion
            ? $this->rolPermisoService->guardarFuncionRol($datos, $idFuncion)
            : $this->rolPermisoService->guardarFuncionEnRoles($datos);

        return ApiResponse::exito($idFuncion ? 'Función actualizada correctamente.' : 'Función creada correctamente.', $funcion);
    }

    public function eliminarFuncion(int $idFuncion): JsonResponse
    {
        $this->rolPermisoService->eliminarFuncion($idFuncion);

        return ApiResponse::exito('Submenú eliminado correctamente.');
    }

    public function cambiarEstadoFuncion(Request $request, int $idFuncion): JsonResponse
    {
        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $funcion = $this->rolPermisoService->cambiarEstadoFuncion($idFuncion, $datos['activo']);

        return ApiResponse::exito('Estado de función actualizado correctamente.', $funcion);
    }

    public function moverFuncion(Request $request, int $idFuncion): JsonResponse
    {
        $datos = $request->validate([
            'direccion' => ['required', 'in:arriba,abajo'],
        ]);

        $funcion = $this->rolPermisoService->moverFuncion($idFuncion, $datos['direccion']);

        return ApiResponse::exito('Orden del submenú actualizado correctamente.', $funcion);
    }

    public function sincronizarRol(int $idRol): JsonResponse
    {
        $total = $this->rolPermisoService->sincronizarRol($idRol);

        return ApiResponse::exito('Usuarios sincronizados con las funciones del rol.', ['usuarios_sincronizados' => $total]);
    }
}
