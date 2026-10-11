<?php

namespace App\Services\Seguridad;

use App\Models\User;
use App\Services\Integraciones\CredencialIntegracionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MicrosoftAuthService
{
    public const CODIGO_CREDENCIAL = 'MICROSOFT_ENTRA_LOGIN_APP';

    public function __construct(private readonly CredencialIntegracionService $credenciales) {}

    public function configuracionPublica(): array
    {
        $credencial = $this->credencialActiva();
        if (! $credencial) {
            return [
                'habilitado' => false,
                'tenant_id' => null,
                'client_id' => null,
                'scopes' => [],
                'authority' => null,
            ];
        }

        $datos = $this->credenciales->obtener((int) $credencial->id);
        $configuracion = $this->json($credencial->configuracion);
        $tenant = trim((string) ($datos['tenant_id'] ?? ''));
        $client = trim((string) ($datos['client_id'] ?? ''));
        $scopes = $this->scopes($datos['scope'] ?? 'openid profile email User.Read');

        return [
            'habilitado' => $tenant !== '' && $client !== '',
            'tenant_id' => $tenant ?: null,
            'client_id' => $client ?: null,
            'scopes' => $scopes,
            'authority' => $tenant !== '' ? "https://login.microsoftonline.com/{$tenant}/v2.0" : null,
            'dominios_permitidos' => array_values(array_filter($configuracion['dominios_permitidos'] ?? [])),
        ];
    }

    public function autenticar(string $accessToken): User
    {
        $credencial = $this->credencialActiva();
        if (! $credencial) {
            throw ValidationException::withMessages([
                'microsoft' => 'El inicio de sesión con Microsoft no está configurado.',
            ]);
        }

        $datos = $this->credenciales->obtener((int) $credencial->id);
        $configuracion = $this->json($credencial->configuracion);
        $tenantEsperado = trim((string) ($datos['tenant_id'] ?? ''));
        $endpoint = trim((string) ($configuracion['graph_me_endpoint'] ?? 'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName'));

        if (mb_strtolower((string) parse_url($endpoint, PHP_URL_HOST)) !== 'graph.microsoft.com') {
            throw ValidationException::withMessages([
                'microsoft' => 'La configuración de Microsoft Graph no es válida.',
            ]);
        }

        $respuesta = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->connectTimeout(8)
            ->get($endpoint);

        if (! $respuesta->successful()) {
            throw ValidationException::withMessages([
                'microsoft' => 'Microsoft no pudo validar la sesión institucional.',
            ]);
        }

        $claims = $this->claimsAccessToken($accessToken);
        if ($tenantEsperado !== '' && filled($claims['tid'] ?? null) && ! hash_equals(mb_strtolower($tenantEsperado), mb_strtolower((string) $claims['tid']))) {
            throw ValidationException::withMessages([
                'microsoft' => 'La cuenta pertenece a un tenant institucional no autorizado.',
            ]);
        }

        $perfil = $respuesta->json();
        $email = mb_strtolower(trim((string) ($perfil['mail'] ?? $perfil['userPrincipalName'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'microsoft' => 'Microsoft no devolvió un correo institucional válido.',
            ]);
        }

        $dominios = array_values(array_filter(array_map(
            fn ($dominio) => mb_strtolower(ltrim(trim((string) $dominio), '@')),
            $configuracion['dominios_permitidos'] ?? [],
        )));
        if ($dominios !== []) {
            $dominio = mb_strtolower((string) str($email)->afterLast('@'));
            if (! in_array($dominio, $dominios, true)) {
                throw ValidationException::withMessages([
                    'microsoft' => 'La cuenta no pertenece a un dominio institucional autorizado.',
                ]);
            }
        }

        $usuario = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $usuario) {
            throw ValidationException::withMessages([
                'microsoft' => 'La cuenta institucional no está autorizada en Revive.',
            ]);
        }

        $rolActivo = DB::table('seguridad.cpu_userrole')
            ->where('id_userrole', $usuario->usr_tipo)
            ->where('activo', true)
            ->exists();

        if ((int) $usuario->usr_estado !== 1 || ! $rolActivo) {
            throw ValidationException::withMessages([
                'microsoft' => 'El usuario está inactivo o su rol no está habilitado.',
            ]);
        }

        return $usuario;
    }

    private function credencialActiva(): ?object
    {
        return DB::table('integraciones.credenciales')
            ->where('codigo', self::CODIGO_CREDENCIAL)
            ->where('activo', true)
            ->first();
    }

    private function json(mixed $valor): array
    {
        if (is_array($valor)) return $valor;
        if (! is_string($valor) || $valor === '') return [];
        return json_decode($valor, true) ?: [];
    }

    private function scopes(mixed $valor): array
    {
        if (is_array($valor)) return array_values(array_filter(array_map('trim', $valor)));
        return array_values(array_filter(preg_split('/[\s,]+/', trim((string) $valor)) ?: []));
    }

    private function claimsAccessToken(string $token): array
    {
        $partes = explode('.', $token);
        if (count($partes) < 2) return [];

        $payload = strtr($partes[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $json = base64_decode($payload, true);
        if ($json === false) return [];

        $claims = json_decode($json, true);
        return is_array($claims) ? $claims : [];
    }
}
