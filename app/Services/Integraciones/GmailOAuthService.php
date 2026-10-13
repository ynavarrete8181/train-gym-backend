<?php

namespace App\Services\Integraciones;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GmailOAuthService
{
    private const CODIGO = 'GMAIL_API_OAUTH';

    private function perfil(): ?object
    {
        return DB::table('integraciones.credenciales')->where('codigo', self::CODIGO)->where('activo', true)->first();
    }

    public function datos(): array
    {
        $registro = $this->perfil();
        if (! $registro) {
            return array_filter(config('services.gmail_api', []), fn ($valor) => $valor !== null && $valor !== '');
        }
        try {
            $datos = json_decode(Crypt::decryptString($registro->datos_cifrados), true, 512, JSON_THROW_ON_ERROR);
            return array_merge(array_filter(config('services.gmail_api', []), fn ($valor) => $valor !== null && $valor !== ''), $datos);
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo leer la configuración cifrada de Gmail.');
        }
    }

    public function estado(): array
    {
        $datos = $this->datos();
        return [
            'configurado' => filled($datos['client_id'] ?? null) && filled($datos['client_secret'] ?? null),
            'conectado' => filled($datos['refresh_token'] ?? null),
            'remitente' => $datos['sender'] ?? null,
        ];
    }

    public function configurar(string $clientId, string $clientSecret, string $sender, ?int $usuarioId): void
    {
        $datos = $this->datos();
        $datos['client_id'] = $clientId;
        $datos['client_secret'] = $clientSecret;
        $datos['sender'] = $sender;
        unset($datos['refresh_token']);
        $this->guardar($datos, $usuarioId);
    }

    public function iniciar(int $usuarioId): string
    {
        $datos = $this->datos();
        if (empty($datos['client_id']) || empty($datos['client_secret'])) throw new RuntimeException('Configure primero el cliente OAuth de Google.');
        $state = Str::random(64);
        Cache::put('gmail-oauth:'.$state, ['usuario_id' => $usuarioId], now()->addMinutes(10));
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $datos['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completar(string $state, string $code): void
    {
        $sesion = Cache::pull('gmail-oauth:'.$state);
        if (! is_array($sesion)) throw new RuntimeException('La autorización expiró o ya fue utilizada.');
        $datos = $this->datos();
        $respuesta = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $datos['client_id'] ?? '',
            'client_secret' => $datos['client_secret'] ?? '',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $respuesta->successful() || ! $respuesta->json('refresh_token')) throw new RuntimeException('Google no entregó una autorización permanente de Gmail.');
        $token = $respuesta->json('access_token');
        $perfil = Http::withToken($token)->connectTimeout(5)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $perfil->successful() || strcasecmp((string) $perfil->json('email'), (string) ($datos['sender'] ?? '')) !== 0) {
            throw new RuntimeException('La cuenta de Google autorizada no coincide con el remitente configurado.');
        }
        $datos['refresh_token'] = $respuesta->json('refresh_token');
        $this->guardar($datos, (int) $sesion['usuario_id']);
    }

    public function desconectar(?int $usuarioId): void
    {
        $datos = $this->datos();
        unset($datos['refresh_token']);
        $this->guardar($datos, $usuarioId);
    }

    private function guardar(array $datos, ?int $usuarioId): void
    {
        $registro = $this->perfil();
        $payload = [
            'datos_cifrados' => Crypt::encryptString(json_encode($datos, JSON_THROW_ON_ERROR)),
            'updated_by' => $usuarioId,
            'updated_at' => now(),
        ];
        if ($registro) {
            DB::table('integraciones.credenciales')->where('id', $registro->id)->update($payload);
            return;
        }
        $proveedor = DB::table('integraciones.proveedores')->where('codigo', 'GOOGLE_GMAIL')->first();
        if (! $proveedor) {
            $id = DB::table('integraciones.proveedores')->insertGetId([
                'codigo' => 'GOOGLE_GMAIL', 'nombre' => 'Gmail API', 'descripcion' => 'Correo OAuth de Google',
                'url_base' => 'https://gmail.googleapis.com', 'tipo' => 'REST',
                'verificar_ssl' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $id = $proveedor->id;
        }
        DB::table('integraciones.credenciales')->insert($payload + [
            'proveedor_id' => $id, 'codigo' => self::CODIGO,
            'nombre' => 'Gmail API Revive', 'tipo_autenticacion' => 'OAUTH2_AUTHORIZATION_CODE_PKCE',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'configuracion' => '{}', 'activo' => true, 'created_by' => $usuarioId, 'created_at' => now(),
        ]);
    }

    private function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/base/integraciones/gmail/callback';
    }
}
