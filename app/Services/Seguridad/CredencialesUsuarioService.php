<?php

namespace App\Services\Seguridad;

use App\Queries\Seguridad\UsuarioQuery;
use App\Services\Comunicaciones\CorreoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CredencialesUsuarioService
{
    public function __construct(
        private UsuarioQuery $usuarioQuery,
        private CorreoService $correoService
    ) {
    }

    public function generarClaveTemporal(): string
    {
        return Str::password(10, true, true, false, false);
    }

    public function reenviarCredenciales(int $usuarioId, ?int $actorId = null): array
    {
        $usuario = $this->usuarioQuery->obtenerPorId($usuarioId);

        if (!$usuario) {
            throw ValidationException::withMessages([
                'usuario' => 'No se encontró el usuario solicitado.',
            ]);
        }

        $destinatario = trim((string) (($usuario['email_credenciales'] ?: null) ?? ($usuario['persona_email'] ?: null) ?? ''));

        if ($destinatario === '') {
            throw ValidationException::withMessages([
                'email' => 'La persona asociada no tiene correo para enviar credenciales.',
            ]);
        }

        $claveTemporal = $this->generarClaveTemporal();

        DB::table('seguridad.usuarios')
            ->where('id', $usuarioId)
            ->update([
                'password_hash' => Hash::make($claveTemporal),
                'requiere_cambio_password' => true,
                'password_temporal_generada_at' => now(),
                'email_credenciales' => $destinatario,
                'updated_id_user' => $actorId,
                'updated_at' => now(),
            ]);

        $envio = $this->correoService->enviarPlantilla(
            'USUARIO_CREDENCIALES',
            $destinatario,
            [
                'nombre' => $usuario['nombre_completo'] ?? 'Usuario',
                'usuario' => $usuario['cedula'] ?: $usuario['email'],
                'clave_temporal' => $claveTemporal,
                'url_login' => config('app.frontend_url', env('FRONTEND_URL', url('/'))),
            ],
            $actorId,
            [
                'usuario_id' => $usuarioId,
                'tipo' => 'credenciales_usuario',
            ]
        );

        return [
            'usuario' => $this->usuarioQuery->obtenerPorId($usuarioId),
            'envio' => $envio,
        ];
    }
}
