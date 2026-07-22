<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Queries\Seguridad\UsuarioQuery;
use App\Services\Seguridad\CredencialesUsuarioService;
use App\Services\Seguridad\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    public function __construct(
        private UsuarioQuery $usuarioQuery,
        private UsuarioService $usuarioService,
        private CredencialesUsuarioService $credencialesUsuarioService
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
            'email_credenciales' => ['nullable', 'string', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'min:6'],
            'enviar_credenciales' => ['nullable', 'boolean'],
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

        if (DB::table('seguridad.usuarios')->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'El usuario ya está en uso.']);
        }
        if (!empty($data['cedula']) && DB::table('seguridad.usuarios')->where('cedula', $data['cedula'])->exists()) {
            throw ValidationException::withMessages(['cedula' => 'La cédula ya está registrada en otro usuario.']);
        }

        $data['password'] = $data['password'] ?? $this->credencialesUsuarioService->generarClaveTemporal();
        $data['requiere_cambio_password'] = true;

        $usuario = $this->usuarioService->crear($request, $data);
        $envio = null;

        if (!empty($data['enviar_credenciales'])) {
            $envio = $this->credencialesUsuarioService->reenviarCredenciales((int) $usuario['id'], $request->user()?->id);
            $usuario = $envio['usuario'] ?? $usuario;
        }

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'usuario' => $usuario,
            'envio' => $envio['envio'] ?? null,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'gimnasio_id' => ['nullable', 'integer'],
            'persona_id' => ['nullable', 'integer'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'string', 'max:150'],
            'email_credenciales' => ['nullable', 'string', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'min:6'],
            'requiere_cambio_password' => ['nullable', 'boolean'],
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

        if (DB::table('seguridad.usuarios')->where('email', $data['email'])->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages(['email' => 'El usuario ya está en uso.']);
        }
        if (!empty($data['cedula']) && DB::table('seguridad.usuarios')->where('cedula', $data['cedula'])->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages(['cedula' => 'La cédula ya está registrada en otro usuario.']);
        }

        $usuario = $this->usuarioService->actualizar($request, $id, $data);

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'usuario' => $usuario,
        ]);
    }

    public function updateAccess(Request $request, int $id)
    {
        $data = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer'],
            'sedes' => ['nullable', 'array'],
            'sedes.*' => ['integer'],
        ]);

        $usuario = $this->usuarioService->actualizarAccesos($request, $id, $data);

        return response()->json([
            'message' => 'Accesos actualizados correctamente.',
            'usuario' => $usuario,
        ]);
    }

    public function resendCredentials(Request $request, int $id)
    {
        $resultado = $this->credencialesUsuarioService->reenviarCredenciales($id, $request->user()?->id);

        return response()->json([
            'message' => $resultado['envio']['message'] ?? 'Credenciales procesadas.',
            'usuario' => $resultado['usuario'],
            'envio' => $resultado['envio'],
        ]);
    }

    public function changeTemporaryPassword(Request $request)
    {
        $data = $request->validate([
            'password_actual' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ]);

        $usuario = $request->user();

        if (!$usuario || !Hash::check($data['password_actual'], $usuario->password_hash)) {
            throw ValidationException::withMessages([
                'password_actual' => 'La contraseña actual no es correcta.',
            ]);
        }

        DB::table('seguridad.usuarios')
            ->where('id', $usuario->id)
            ->update([
                'password_hash' => Hash::make($data['password']),
                'requiere_cambio_password' => false,
                'password_temporal_generada_at' => null,
                'updated_id_user' => $usuario->id,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
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
