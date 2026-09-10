<?php

namespace App\Services\Notificaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExpoPushService
{
    public function enviar(string $token, string $titulo, string $cuerpo, array $data = []): array
    {
        $servicio = DB::table('integraciones.servicios as s')
            ->join('integraciones.proveedores as p', 'p.id', '=', 's.proveedor_id')
            ->where('s.codigo', 'PUSH_ENVIAR')
            ->where('s.activo', true)
            ->where('p.activo', true)
            ->select('s.*', 'p.url_base', 'p.verificar_ssl')
            ->first();

        if (! $servicio) {
            throw new \RuntimeException('No existe un servicio push activo.');
        }

        $headers = is_string($servicio->headers) ? (json_decode($servicio->headers, true) ?: []) : (array) $servicio->headers;
        $url = rtrim((string) $servicio->url_base, '/').'/'.ltrim((string) $servicio->endpoint, '/');
        $response = Http::withHeaders($headers)
            ->withOptions(['verify' => (bool) ($servicio->verificar_ssl ?? true)])
            ->timeout((int) ($servicio->timeout_segundos ?: 20))
            ->post($url, [
                'to' => $token,
                'title' => $titulo,
                'body' => $cuerpo,
                'sound' => 'default',
                'data' => $data,
            ]);

        $json = $response->json();
        $estado = data_get($json, 'data.status');
        $ok = $response->successful() && ($estado === null || $estado === 'ok');

        return [
            'ok' => $ok,
            'http_status' => $response->status(),
            'respuesta' => $json,
        ];
    }
}
