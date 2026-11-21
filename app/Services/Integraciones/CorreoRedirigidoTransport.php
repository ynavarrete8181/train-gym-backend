<?php

namespace App\Services\Integraciones;

use App\Contracts\Integraciones\CorreoTransportContract;
use Illuminate\Support\Facades\Log;

/**
 * Fuera de producción ningún correo llega a los clientes: todos se desvían a la
 * dirección de REVIVE_CORREO_REDIRIGIR_A y el asunto indica a quién iba. Si esa
 * dirección no está configurada, el correo no se envía (solo queda en el log).
 * Así se puede trabajar con una copia de los datos reales sin escribirle a nadie.
 */
class CorreoRedirigidoTransport implements CorreoTransportContract
{
    public function __construct(
        private readonly CorreoTransportContract $transporte,
        private readonly ?string $destino,
    ) {
    }

    public function enviar(array $mensaje): array
    {
        $original = collect([
            'para' => [$mensaje['para'] ?? null],
            'cc' => $mensaje['cc'] ?? [],
            'cco' => $mensaje['cco'] ?? [],
        ])->map(fn ($lista) => collect($lista)->filter()->implode(', '))->filter();
        $resumen = $original->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | ');

        if (! $this->destino || ! filter_var($this->destino, FILTER_VALIDATE_EMAIL)) {
            Log::info('Correo no enviado (entorno de desarrollo sin REVIVE_CORREO_REDIRIGIR_A).', [
                'destinatarios' => $resumen, 'asunto' => $mensaje['asunto'] ?? null,
            ]);

            return ['ok' => false, 'http_status' => 0, 'respuesta' => ['mensaje' => 'Correo no enviado en desarrollo: falta REVIVE_CORREO_REDIRIGIR_A.']];
        }

        $mensaje['para'] = $this->destino;
        $mensaje['cc'] = [];
        $mensaje['cco'] = [];
        $mensaje['asunto'] = '[DESARROLLO → ' . ($original->get('para') ?: 'sin destinatario') . '] ' . ($mensaje['asunto'] ?? '');
        $mensaje['html'] = '<p style="font-family:Arial,sans-serif;font-size:12px;color:#805b00;background:#fff8e9;border:1px solid #e6c46c;padding:8px;border-radius:6px">'
            . 'Correo de desarrollo desviado. Destinatarios originales: ' . e($resumen ?: 'ninguno') . '</p>'
            . ($mensaje['html'] ?? '');

        return $this->transporte->enviar($mensaje);
    }
}
