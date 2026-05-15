<?php

namespace App\Http\Controllers;

use App\Models\AuthUsuario;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private AuditService $auditService)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            //'gimnasio_id' => ['required', 'integer'],
        ]);

        $u = AuthUsuario::query()
            // ->where('gimnasio_id', $data['gimnasio_id'])
            ->where('email', $data['email'])
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$u || !$u->password_hash || !Hash::check($data['password'], $u->password_hash)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

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
            ],
        ]);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $u->id,
                'email' => $u->email,
                'gimnasio_id' => $u->gimnasio_id,
            ],
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
        return response()->json($request->user());
    }
}
