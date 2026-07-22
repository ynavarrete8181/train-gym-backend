<?php

namespace App\Http\Controllers;

use App\Models\AuthUsuario;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(private AuditService $auditService)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'cedula' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $cedula = trim((string) $data['cedula']);
        $password = (string) $data['password'];

        $u = AuthUsuario::query()
            ->select('seguridad.usuarios.*')
            ->leftJoin('core.personas', 'core.personas.id', '=', 'seguridad.usuarios.persona_id')
            ->where(function ($query) use ($cedula) {
                $query->where('seguridad.usuarios.cedula', $cedula)
                    ->orWhere('core.personas.numero_identificacion', $cedula);
            })
            ->first();

        if (!$u) {
            return response()->json([
                'status' => 'warning',
                'message' => 'La cédula ingresada no está registrada.',
            ], 404);
        }

        if (strtoupper((string) $u->estado) !== 'ACTIVO') {
            return response()->json([
                'status' => 'warning',
                'message' => 'Tu usuario no se encuentra activo. Contacta al administrador.',
            ], 403);
        }

        if (!$u->password_hash) {
            return response()->json([
                'status' => 'warning',
                'message' => 'Tu usuario no tiene una contraseña configurada.',
            ], 422);
        }

        try {
            $passwordIsValid = Hash::check($password, $u->password_hash);
        } catch (RuntimeException $exception) {
            if ($u->password_hash === $password || $u->password_hash === md5($password)) {
                $passwordIsValid = true;
                
                DB::table('seguridad.usuarios')
                    ->where('id', $u->id)
                    ->update([
                        'password_hash' => Hash::make($password),
                        'updated_at' => now(),
                    ]);
            } else {
                report($exception);

                return response()->json([
                    'status' => 'error',
                    'message' => 'La contraseña del usuario no tiene un formato válido. Actualízala desde administración.',
                ], 500);
            }
        }

        if (!$passwordIsValid) {
            return response()->json([
                'status' => 'warning',
                'message' => 'La contraseña ingresada es incorrecta.',
            ], 401);
        }

        $loginAt = now();

        DB::table('seguridad.usuarios')
            ->where('id', $u->id)
            ->update([
                'ultimo_login_at' => $loginAt,
                'updated_at' => $loginAt,
            ]);

        $u->ultimo_login_at = $loginAt;
        $token = $u->createToken('train-gym-web')->plainTextToken;

        $this->auditService->activity($request, 'auth', 'login', [
            'tabla' => 'auth_sesion',
            'operacion' => 'I',
            'registro_id' => $u->id,
            'actor_usuario_id' => $u->id,
            'gimnasio_id' => $u->gimnasio_id,
            'datos_despues' => [
                'usuario_id' => $u->id,
                'email' => $u->email,
                'cedula' => $cedula,
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Inicio de sesión correcto.',
            'token' => $token,
            'user' => $this->mapUserPayload($u),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $tokenId = $user?->currentAccessToken()?->id;

        $this->auditService->activity($request, 'auth', 'logout', [
            'tabla' => 'auth_sesion',
            'operacion' => 'D',
            'registro_id' => $tokenId,
            'actor_usuario_id' => $user?->id,
            'gimnasio_id' => $user?->gimnasio_id,
            'datos_antes' => [
                'usuario_id' => $user?->id,
                'email' => $user?->email,
                'token_id' => $tokenId,
            ],
        ]);

        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'ok']);
    }

    public function me(Request $request)
    {
        return response()->json($this->mapUserPayload($request->user()));
    }

    private function mapUserPayload(?AuthUsuario $u): ?array
    {
        if (!$u) {
            return null;
        }

        $persona = null;
        if ($u->persona_id) {
            $persona = DB::table('core.personas')
                ->select('id', 'nombres', 'apellidos', 'numero_identificacion')
                ->where('id', $u->persona_id)
                ->first();
        }

        return [
            'id' => $u->id,
            'email' => $u->email,
            'gimnasio_id' => $u->gimnasio_id,
            'persona_id' => $u->persona_id,
            'requiere_cambio_password' => (bool) ($u->requiere_cambio_password ?? false),
            'password_temporal_generada_at' => $u->password_temporal_generada_at,
            'ultimo_login_at' => $u->ultimo_login_at,
            'username' => trim(($persona->nombres ?? '') . ' ' . ($persona->apellidos ?? '')) ?: $u->email,
            'name' => trim(($persona->nombres ?? '') . ' ' . ($persona->apellidos ?? '')) ?: $u->email,
            'cedula' => $u->cedula ?: ($persona->numero_identificacion ?? null),
            'roles' => DB::table('seguridad.usuario_roles as ur')
                ->join('seguridad.roles as r', 'r.id', '=', 'ur.rol_id')
                ->where('ur.usuario_id', $u->id)
                ->orderBy('r.nombre')
                ->get(['r.id', 'r.codigo', 'r.nombre'])
                ->map(fn ($rol) => [
                    'id' => (int) $rol->id,
                    'codigo' => $rol->codigo,
                    'nombre' => $rol->nombre,
                ])
                ->all(),
            'sedes' => DB::table('seguridad.usuario_sedes as us')
                ->join('core.sedes as s', 's.id', '=', 'us.sede_id')
                ->where('us.usuario_id', $u->id)
                ->where('us.activo', true)
                ->where('s.activa', true)
                ->orderBy('s.nombre')
                ->get(['s.id', 's.nombre'])
                ->map(fn ($sede) => [
                    'id' => (int) $sede->id,
                    'nombre' => $sede->nombre,
                ])
                ->all(),
        ];
    }
}
