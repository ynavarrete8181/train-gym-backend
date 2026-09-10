<?php

namespace App\Http\Middleware;

use App\Models\Seguridad\TokenAcceso;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AutenticarTokenBase
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenPlano = $request->bearerToken();

        if (! $tokenPlano) {
            return response()->json(['ok' => false, 'mensaje' => 'Token de acceso no enviado.'], 401);
        }

        $token = TokenAcceso::query()
            ->where('token_hash', hash('sha256', $tokenPlano))
            ->where(function ($query): void {
                $query->whereNull('expira_en')->orWhere('expira_en', '>', now());
            })
            ->first();

        if (! $token) {
            return response()->json(['ok' => false, 'mensaje' => 'Token de acceso inválido o expirado.'], 401);
        }

        $usuario = User::query()->find($token->id_usuario);

        $rolActivo = $usuario && DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $usuario->usr_tipo)
            ->where('activo', true)
            ->exists();

        if (! $usuario || (int) $usuario->usr_estado !== 1 || ! $rolActivo) {
            return response()->json(['ok' => false, 'mensaje' => 'Usuario inactivo o no encontrado.'], 403);
        }

        $token->update(['ultimo_uso_en' => now()]);
        Auth::setUser($usuario);
        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
