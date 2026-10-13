<?php

namespace App\Services\Integraciones;

use App\Contracts\Integraciones\CorreoTransportContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GmailApiCorreoTransport implements CorreoTransportContract
{
    public function enviar(array $mensaje): array
    {
        $config = array_merge(config('services.gmail_api', []), app(GmailOAuthService::class)->datos());
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));
        $sender = trim((string) ($config['sender'] ?? ''));

        if (! $clientId || ! $clientSecret || ! $refreshToken || ! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Gmail API no está configurada. Configure el cliente OAuth, refresh token y remitente.');
        }

        $token = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        if (! $token->successful() || ! is_string($token->json('access_token'))) {
            throw new RuntimeException('No fue posible renovar el acceso OAuth de Gmail (HTTP '.$token->status().').');
        }

        $to = trim((string) ($mensaje['para'] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El destinatario del correo no es válido.');
        }

        $headers = ['From: '. $sender, 'To: '. $to];
        foreach (['cc' => 'Cc', 'cco' => 'Bcc'] as $campo => $cabecera) {
            $correos = array_values(array_filter($mensaje[$campo] ?? [], fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
            if ($correos) {
                $headers[] = $cabecera.': '.implode(', ', $correos);
            }
        }

        $subject = trim(str_replace(["\r", "\n"], '', (string) ($mensaje['asunto'] ?? '')));
        $headers[] = 'Subject: =?UTF-8?B?'.base64_encode($subject).'?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $raw = implode("\r\n", $headers)."\r\n\r\n".chunk_split(base64_encode((string) ($mensaje['html'] ?? '')), 76, "\r\n");
        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $response = Http::withToken($token->json('access_token'))->connectTimeout(5)->timeout(20)
            ->acceptJson()->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $encoded,
            ]);

        $error = $response->json('error', []);
        $error = is_array($error) ? $error : [];
        $razones = collect($error['errors'] ?? [])->pluck('reason')
            ->merge(collect($error['details'] ?? [])->pluck('reason'))
            ->filter(fn ($valor) => is_string($valor) && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $valor))
            ->values()->all();
        $codigo = (string) ($error['status'] ?? '');
        $razon = $razones[0] ?? $codigo;
        $razon = preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $razon) ? $razon : 'UNKNOWN';
        $temporal = in_array($response->status(), [408, 429, 500, 502, 503, 504], true)
            || in_array($razon, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError', 'quotaExceeded'], true);
        $causas = [
            'accessNotConfigured' => 'La API de Gmail no está habilitada en el proyecto de Google Cloud.',
            'SERVICE_DISABLED' => 'La API de Gmail está deshabilitada; verifica el proyecto de Google Cloud.',
            'ACCESS_TOKEN_SCOPE_INSUFFICIENT' => 'Falta el permiso gmail.send. Vuelve a conectar Gmail con el alcance correcto.',
            'insufficientPermissions' => 'El token OAuth no tiene permiso para enviar correos. Reconecta la cuenta.',
            'PERMISSION_DENIED' => 'Google rechazó el permiso de envío. Verifica Gmail API, scopes y restricciones del proyecto.',
            'rateLimitExceeded' => 'Se alcanzó temporalmente un límite de solicitudes de Gmail.',
            'userRateLimitExceeded' => 'La cuenta alcanzó temporalmente su límite de solicitudes.',
            'quotaExceeded' => 'Se alcanzó la cuota de Gmail API; revisa sus límites.',
        ];
        $diagnostico = $causas[$razon] ?? ($causas[$codigo] ?? 'Revisa los permisos OAuth, la API habilitada y las restricciones de Google Cloud.');
        return [
            'ok' => $response->successful(),
            'reintentable' => $temporal,
            'http_status' => $response->status(),
            'respuesta' => $response->successful()
                ? ['aceptado' => true, 'proveedor' => 'GMAIL_API', 'message_id' => $response->json('id')]
                : ['mensaje' => 'Gmail API HTTP '.$response->status().' ['.$razon.']: '.$diagnostico, 'codigo' => $codigo, 'razon' => $razon, 'diagnostico' => $diagnostico, 'reintentable' => $temporal],
        ];
    }
}
