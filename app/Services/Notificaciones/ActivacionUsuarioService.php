<?php

namespace App\Services\Notificaciones;

use App\Models\User;
use App\Services\Concerns\RegistraAuditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ActivacionUsuarioService
{
    use RegistraAuditoria;

    public function enlaceDisponible(string $referencia): bool
    {
        return DB::table('notificaciones.tokens_activacion')
            ->where('referencia_publica', $referencia)
            ->whereNull('usado_at')
            ->whereNull('invalidado_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function crearEnlace(User $usuario): array
    {
        DB::table('notificaciones.tokens_activacion')->where('usuario_id', $usuario->id)->whereNull('usado_at')->update(['invalidado_at' => now(), 'updated_at' => now()]);
        $token = Str::upper(Str::random(10));
        $referencia = (string) Str::uuid();
        $expira = now()->addHours((int) config('services.notificaciones.activacion_horas', 48));
        DB::table('notificaciones.tokens_activacion')->insert([
            'usuario_id' => $usuario->id, 'token_hash' => hash('sha256', $token), 'referencia_publica' => $referencia, 'expires_at' => $expira,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->auditar('notificaciones', 'CREAR', 'notificaciones.tokens_activacion', $usuario->id, null, null, "Enlace de activación generado para {$usuario->email}.");

        return [
            'url' => rtrim((string) config('services.notificaciones.frontend_url'), '/').'/activar-cuenta/'.$referencia,
            'codigo' => $token,
            'expira' => $expira,
        ];
    }

    public function activar(string $token, string $password, ?string $referencia = null): User
    {
        return DB::transaction(function () use ($token, $password, $referencia): User {
            $registro = DB::table('notificaciones.tokens_activacion')
                ->where('token_hash', hash('sha256', $token))
                ->when($referencia, fn ($query) => $query->where('referencia_publica', $referencia))
                ->lockForUpdate()
                ->first();

            if (! $registro) {
                throw new \RuntimeException('El código de verificación no es correcto.');
            }
            if ($registro->usado_at) {
                throw new \RuntimeException('Este código de verificación ya fue utilizado.');
            }
            if ($registro->invalidado_at) {
                throw new \RuntimeException('Este código de verificación fue invalidado. Solicita uno nuevo.');
            }
            if (Carbon::parse($registro->expires_at)->isPast()) {
                throw new \RuntimeException('El código de verificación ha expirado. Solicita uno nuevo.');
            }

            $usuario = User::findOrFail($registro->usuario_id);
            $usuario->update(['password' => Hash::make($password), 'email_verified_at' => now()]);
            DB::table('seguridad.tokens_acceso')->where('id_usuario', $usuario->id)->delete();
            DB::table('notificaciones.tokens_activacion')->where('id', $registro->id)->update(['usado_at' => now(), 'updated_at' => now()]);

            return $usuario;
        });
    }
}
