<?php

namespace App\Http\Controllers\Api\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Institucional\Contexto;
use App\Models\User;
use App\Services\Institucional\EstructuraInstitucionalService;
use App\Services\Notificaciones\NotificacionUsuarioService;
use App\Services\Seguridad\UsuarioService;
use App\Support\ApiResponse;
use App\Support\ReglasClave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarioService, private readonly EstructuraInstitucionalService $estructuraService, private readonly NotificacionUsuarioService $notificacionService) {}

    public function index(Request $request): JsonResponse
    {
        $usuarios = $this->usuarioService->listar($request->only(['busqueda', 'usuario', 'correo', 'cedula', 'rol', 'estado', 'per_page']));

        return ApiResponse::exito('Usuarios consultados correctamente.', $usuarios->items(), [
            'pagina_actual' => $usuarios->currentPage(),
            'por_pagina' => $usuarios->perPage(),
            'total' => $usuarios->total(),
            'ultima_pagina' => $usuarios->lastPage(),
            'opciones_filtro' => $this->usuarioService->opcionesFiltro(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['required', 'string', 'max:120'],
            'cedula' => ['required', 'string', 'max:20', Rule::unique(User::class, 'cedula')],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'confirmed', ReglasClave::segura()],
            'usr_tipo' => ['required', 'integer'],
            'usr_estado' => ['nullable', 'integer', Rule::in([1, 0])],
            'funciones' => ['nullable', 'array'],
            'funciones.*' => ['string', 'max:120'],
            'contextos' => ['nullable', 'array'],
            'contextos.*' => ['integer', 'distinct', Rule::exists(Contexto::class, 'id_contexto')->where('activo', true)],
        ]);

        $this->validarRol((int) $datos['usr_tipo']);
        $datos['contextos'] = $this->normalizarContextosSegunRol((int) $datos['usr_tipo'], $datos['contextos'] ?? []);

        $usuario = DB::transaction(function () use ($datos, $request) {
            $usuario = $this->usuarioService->crear($datos);
            $this->estructuraService->asignarUsuario($usuario->id, $datos['contextos']);
            $this->notificacionService->registrarPendiente($usuario, $request->user()?->id);

            return $usuario;
        });

        return ApiResponse::exito('Usuario creado correctamente.', $usuario, codigo: 201);
    }

    public function show(User $usuario): JsonResponse
    {
        return ApiResponse::exito('Usuario obtenido correctamente.', [
            'usuario' => $usuario,
            'funciones' => $this->usuarioService->obtenerFuncionesUsuario($usuario->id),
            'contextos' => $this->estructuraService->contextosUsuario($usuario->id),
        ]);
    }

    public function update(Request $request, User $usuario): JsonResponse
    {
        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['required', 'string', 'max:120'],
            'cedula' => ['required', 'string', 'max:20', Rule::unique(User::class, 'cedula')->ignore($usuario->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($usuario->id)],
            'usr_tipo' => ['required', 'integer'],
            'usr_estado' => ['required', 'integer', Rule::in([1, 0])],
            'funciones' => ['nullable', 'array'],
            'funciones.*' => ['string', 'max:120'],
            'contextos' => ['nullable', 'array'],
            'contextos.*' => ['integer', 'distinct', Rule::exists(Contexto::class, 'id_contexto')->where('activo', true)],
        ]);

        $this->validarRol((int) $datos['usr_tipo']);
        $this->validarCambioPropio($request, $usuario, (int) $datos['usr_tipo'], (int) $datos['usr_estado']);
        $datos['contextos'] = $this->normalizarContextosSegunRol((int) $datos['usr_tipo'], $datos['contextos'] ?? []);

        $usuarioActualizado = DB::transaction(function () use ($usuario, $datos) {
            $actualizado = $this->usuarioService->actualizar($usuario, $datos);
            $this->estructuraService->asignarUsuario($usuario->id, $datos['contextos']);
            if (array_key_exists('funciones', $datos)) {
                $this->usuarioService->guardarFuncionesUsuario($usuario->id, (int) $datos['usr_tipo'], $datos['funciones']);
            }

            return $actualizado;
        });

        return ApiResponse::exito('Usuario actualizado correctamente.', $usuarioActualizado);
    }

    public function cambiarEstado(Request $request, User $usuario): JsonResponse
    {
        $datos = $request->validate([
            'usr_estado' => ['required', 'integer', Rule::in([1, 0])],
        ]);

        $this->validarCambioPropio($request, $usuario, (int) $usuario->usr_tipo, (int) $datos['usr_estado']);
        $usuarioActualizado = $this->usuarioService->cambiarEstado($usuario, (int) $datos['usr_estado']);

        return ApiResponse::exito('Estado actualizado correctamente.', $usuarioActualizado);
    }

    public function restablecerClave(Request $request, User $usuario): JsonResponse
    {
        $this->notificacionService->solicitarRestablecimiento($usuario, $request->user()?->id);

        return ApiResponse::exito('Se programó el correo para restablecer la contraseña.', codigo: 202);
    }

    public function funciones(User $usuario): JsonResponse
    {
        return ApiResponse::exito('Funciones del usuario consultadas correctamente.', [
            'funciones' => $this->usuarioService->obtenerFuncionesUsuario($usuario->id),
        ]);
    }

    public function guardarFunciones(Request $request, User $usuario): JsonResponse
    {
        $datos = $request->validate([
            'funciones' => ['present', 'array'],
            'funciones.*' => ['string', 'max:120'],
        ]);

        $this->usuarioService->guardarFuncionesUsuario($usuario->id, (int) $usuario->usr_tipo, $datos['funciones']);

        return ApiResponse::exito('Funciones del usuario actualizadas correctamente.');
    }

    public function guardarAccesos(Request $request, User $usuario): JsonResponse
    {
        $datos = $request->validate([
            'usr_tipo' => ['required', 'integer'],
            'funciones' => ['present', 'array'],
            'funciones.*' => ['string', 'max:120'],
        ]);

        $this->validarRol((int) $datos['usr_tipo']);
        $this->validarCambioPropio($request, $usuario, (int) $datos['usr_tipo']);
        $this->usuarioService->actualizarAccesos($usuario, (int) $datos['usr_tipo'], $datos['funciones']);

        return ApiResponse::exito('Rol y permisos del usuario actualizados correctamente.');
    }

    public function sincronizarFuncionesRol(User $usuario): JsonResponse
    {
        $this->usuarioService->sincronizarFuncionesDesdeRol($usuario->id, (int) $usuario->usr_tipo);

        return ApiResponse::exito('Funciones sincronizadas desde el rol correctamente.');
    }

    private function validarRol(int $idRol): void
    {
        $existe = DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $idRol)
            ->where('activo', true)
            ->exists();

        if (! $existe) {
            throw ValidationException::withMessages([
                'usr_tipo' => 'El rol seleccionado no existe o está inactivo.',
            ]);
        }
    }

    private function validarCambioPropio(Request $request, User $usuario, int $idRol, ?int $estado = null): void
    {
        if ((int) $request->user()?->id !== (int) $usuario->id) {
            return;
        }

        if ((int) $usuario->usr_tipo !== $idRol) {
            throw ValidationException::withMessages([
                'usr_tipo' => 'No puedes cambiar tu propio rol desde Usuarios. Usa otra cuenta administradora para evitar perder acceso.',
            ]);
        }

        if ($estado !== null && $estado !== 1) {
            throw ValidationException::withMessages([
                'usr_estado' => 'No puedes inactivar tu propio usuario mientras tienes la sesión abierta.',
            ]);
        }
    }

    private function normalizarContextosSegunRol(int $idRol, array $contextos): array
    {
        $rol = DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $idRol)
            ->value('role');

        if (in_array($rol, ['SUPERADMINISTRADOR', 'DEPORTISTA', 'RESPONSABLE'], true)) {
            return [];
        }

        if (empty($contextos)) {
            throw ValidationException::withMessages([
                'contextos' => 'Selecciona al menos una asignación operativa para este rol.',
            ]);
        }

        return array_values(array_unique(array_map('intval', $contextos)));
    }
}
