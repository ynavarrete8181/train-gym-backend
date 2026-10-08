<?php

namespace App\Services\Integraciones;

use App\Contracts\Integraciones\CorreoTransportContract;
use Illuminate\Support\Facades\Mail;

class SmtpCorreoTransport implements CorreoTransportContract
{
    public function enviar(array $mensaje): array
    {
        Mail::mailer('smtp')->html((string) $mensaje['html'], function ($mail) use ($mensaje): void {
            $mail->to((string) $mensaje['para'])
                ->subject((string) $mensaje['asunto']);

            $from = (string) config('mail.from.address');
            $fromName = (string) config('mail.from.name');
            if ($from !== '') {
                $mail->from($from, $fromName ?: null);
            }

            foreach (($mensaje['cc'] ?? []) as $correo) {
                if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $mail->cc($correo);
                }
            }

            foreach (($mensaje['cco'] ?? []) as $correo) {
                if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $mail->bcc($correo);
                }
            }

            foreach (($mensaje['adjuntos'] ?? []) as $adjunto) {
                $mail->attachData(
                    (string) ($adjunto['contenido'] ?? ''),
                    (string) ($adjunto['nombre'] ?? 'archivo.bin'),
                    ['mime' => (string) ($adjunto['mime'] ?? 'application/octet-stream')]
                );
            }
        });

        return [
            'ok' => true,
            'http_status' => 202,
            'respuesta' => ['aceptado' => true, 'proveedor' => 'SMTP'],
        ];
    }
}
