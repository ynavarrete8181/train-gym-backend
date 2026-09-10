<?php

namespace App\Services\Seguridad;

use App\Models\Seguridad\TokenAcceso;
use App\Models\User;
use App\Services\Auditoria\AuditoriaServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private readonly AuditoriaServicio $auditoria) {}

    public function intentarLogin(string $email, string $password): ?array
    {
        $emailNormalizado = mb_strtolower(trim($email));
        $usuario = User::query()->where('email', $emailNormalizado)->first();

        if (! $usuario || ! Hash::check($password, $usuario->password) || ! $this->usuarioHabilitado($usuario)) {
            $this->auditoria->registrarAcceso($usuario?->id, $emailNormalizado, 'LOGIN_FALLIDO', ! $usuario ? 'Usuario no encontrado.' : 'Credenciales inválidas o usuario deshabilitado.');
            return null;
        }

        $this->auditoria->registrarAcceso($usuario->id, $usuario->email, 'LOGIN');
        return $this->emitirSesion($usuario, 'web');
    }

    public function iniciarSesionMicrosoft(User $usuario): ?array
    {
        if (! $this->usuarioHabilitado($usuario)) {
            return null;
        }

        return $this->emitirSesion($usuario, 'app-microsoft');
    }

    public function cerrarSesion(string $tokenPlano): void
    {
        $token = TokenAcceso::query()->where('token_hash', hash('sha256', $tokenPlano))->first();
        $usuario = $token ? User::query()->find($token->id_usuario) : null;

        TokenAcceso::query()->where('token_hash', hash('sha256', $tokenPlano))->delete();

        if ($usuario) {
            $this->auditoria->registrarAcceso($usuario->id, $usuario->email, 'LOGOUT');
        }
    }

    private function usuarioHabilitado(User $usuario): bool
    {
        $rolActivo = DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $usuario->usr_tipo)
            ->where('activo', true)
            ->exists();

        return (int) $usuario->usr_estado === 1 && $rolActivo;
    }

    private function emitirSesion(User $usuario, string $nombre): array
    {
        $tokenPlano = Str::random(80);

        TokenAcceso::create([
            'id_usuario' => $usuario->id,
            'nombre' => $nombre,
            'token_hash' => hash('sha256', $tokenPlano),
            'expira_en' => now()->addHours(12),
        ]);

        $usuario->setAttribute('rol_nombre', DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $usuario->usr_tipo)
            ->value('role'));

        return [
            'token' => $tokenPlano,
            'usuario' => $usuario,
        ];
    }
}
