<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Queries\Seguridad\UsuarioQuery;
use App\Services\Seguridad\UsuarioService;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function __construct(
        private UsuarioQuery $usuarioQuery,
        private UsuarioService $usuarioService
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
            'estado' => ['nullable', 'string', 'in:ACTIVO,INACTIVO,BLOQUEADO'],
            'rol_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->usuarioQuery->listar($filters));
    }

    public function show(int $id)
    {
        $usuario = $this->usuarioQuery->obtenerPorId($id);

        if (!$usuario) {
            return response()->json([
                'message' => 'No se encontró el usuario solicitado.',
            ], 404);
        }

        return response()->json($usuario);
    }

    public function catalogos()
    {
        return response()->json([
            'roles' => $this->usuarioQuery->roles(),
            'personas' => $this->usuarioQuery->personasDisponibles(),
            'sedes' => $this->usuarioQuery->sedes(),
        ]);
    }

    public function roles()
    {
        return response()->json($this->usuarioQuery->roles());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'gimnasio_id' => ['nullable', 'integer'],
            'persona_id' => ['nullable', 'integer'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:6'],
            'estado' => ['nullable', 'string', 'in:ACTIVO,INACTIVO,BLOQUEADO'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer'],
            'sedes' => ['nullable', 'array'],
            'sedes.*' => ['integer'],
        ], [
            'email.required' => 'El usuario es obligatorio.',
            'email.max' => 'El usuario no puede superar 150 caracteres.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ]);

        if (\Illuminate\Support\Facades\DB::table('seguridad.usuarios')->where('email', $data['email'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'El usuario ya está en uso.']);
        }
        if (!empty($data['cedula']) && \Illuminate\Support\Facades\DB::table('seguridad.usuarios')->where('cedula', $data['cedula'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cedula' => 'La cédula ya está registrada en otro usuario.']);
        }

        $usuario = $this->usuarioService->crear($request, $data);

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'usuario' => $usuario,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'gimnasio_id' => ['nullable', 'integer'],
            'persona_id' => ['nullable', 'integer'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'min:6'],
            'estado' => ['nullable', 'string', 'in:ACTIVO,INACTIVO,BLOQUEADO'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer'],
            'sedes' => ['nullable', 'array'],
            'sedes.*' => ['integer'],
        ], [
            'email.required' => 'El usuario es obligatorio.',
            'email.max' => 'El usuario no puede superar 150 caracteres.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ]);

        if (\Illuminate\Support\Facades\DB::table('seguridad.usuarios')->where('email', $data['email'])->where('id', '!=', $id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'El usuario ya está en uso.']);
        }
        if (!empty($data['cedula']) && \Illuminate\Support\Facades\DB::table('seguridad.usuarios')->where('cedula', $data['cedula'])->where('id', '!=', $id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cedula' => 'La cédula ya está registrada en otro usuario.']);
        }

        $usuario = $this->usuarioService->actualizar($request, $id, $data);

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'usuario' => $usuario,
        ]);
    }

    public function changeStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'estado' => ['required', 'string', 'in:ACTIVO,INACTIVO,BLOQUEADO'],
        ]);

        $usuario = $this->usuarioService->cambiarEstado($request, $id, $data['estado']);

        return response()->json([
            'message' => 'Estado actualizado correctamente.',
            'usuario' => $usuario,
        ]);
    }
}
