<?php

namespace App\Services\Integraciones;

use App\Contracts\Integraciones\CorreoTransportContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftGraphCorreoTransport implements CorreoTransportContract
{
    public function __construct(private readonly CredencialIntegracionService $credenciales) {}

    public function enviar(array $mensaje): array
    {
        $servicio = DB::table('integraciones.servicios as s')->join('integraciones.proveedores as p', 'p.id', '=', 's.proveedor_id')
            ->where('s.codigo', 'CORREO_ENVIAR')->where('s.activo', true)->where('p.activo', true)
            ->select('s.*', 'p.url_base', 'p.verificar_ssl')->first();
        if (! $servicio) {
            throw new RuntimeException('El servicio de correo no está configurado o está inactivo.');
        }
        if (! $servicio->credencial_id) {
            throw new RuntimeException('El servicio de correo no tiene un perfil de autenticación asignado.');
        }

        $credencial = $this->credenciales->obtener((int) $servicio->credencial_id);
        $sender = trim((string) ($credencial['sender'] ?? ''));
        if ($sender === '') {
            throw new RuntimeException('El remitente de Microsoft Graph no está configurado.');
        }
        $url = rtrim($servicio->url_base, '/').str_replace('{sender}', rawurlencode($sender), $servicio->endpoint);
        $response = Http::timeout((int) $servicio->timeout_segundos)->connectTimeout(10)
            ->withOptions(['verify' => (bool) $servicio->verificar_ssl])
            ->withToken($this->token((int) $servicio->credencial_id, $credencial, (bool) $servicio->verificar_ssl))->acceptJson()->post($url, [
                'message' => ['subject' => $mensaje['asunto'], 'body' => ['contentType' => 'HTML', 'content' => $mensaje['html']],
                    'toRecipients' => $this->destinatarios([$mensaje['para']]), 'ccRecipients' => $this->destinatarios($mensaje['cc'] ?? []), 'bccRecipients' => $this->destinatarios($mensaje['cco'] ?? [])],
            ]);

        return ['ok' => $response->successful(), 'http_status' => $response->status(),
            'respuesta' => $response->successful() ? ['aceptado' => true] : ['error' => data_get($response->json(), 'error.code'), 'mensaje' => data_get($response->json(), 'error.message')]];
    }

    private function destinatarios(array $correos): array
    {
        return collect($correos)->filter(fn ($correo) => filter_var($correo, FILTER_VALIDATE_EMAIL))->unique()->map(fn ($correo) => ['emailAddress' => ['address' => $correo]])->values()->all();
    }

    private function token(int $credencialId, array $datos, bool $verificarSsl): string
    {
        return Cache::remember('integraciones.credencial.'.$credencialId.'.token', now()->addMinutes(50), function () use ($credencialId, $datos, $verificarSsl): string {
            $registro = DB::table('integraciones.credenciales')->find($credencialId);
            $tenant = trim((string) ($datos['tenant_id'] ?? ''));
            $client = trim((string) ($datos['client_id'] ?? ''));
            $secret = trim((string) ($datos['client_secret'] ?? ''));
            if ($tenant === '' || $client === '' || $secret === '') {
                throw new RuntimeException('Faltan credenciales de Microsoft Graph.');
            }
            $tokenUrl = str_replace('{tenant_id}', rawurlencode($tenant), (string) $registro->token_url);
            $response = Http::withOptions(['verify' => $verificarSsl])->asForm()->timeout(25)->connectTimeout(10)->post($tokenUrl, [
                'grant_type' => 'client_credentials', 'client_id' => $client, 'client_secret' => $secret, 'scope' => $datos['scope'] ?? '',
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException('No fue posible autenticarse con Microsoft Graph.');
            }

            return $token;
        });
    }
}
