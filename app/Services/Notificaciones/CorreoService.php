<?php

namespace App\Services\Notificaciones;

use App\Contracts\Integraciones\CorreoTransportContract;
use Illuminate\Support\Facades\DB;
use Throwable;

class CorreoService
{
    public function __construct(private readonly CorreoTransportContract $transport) {}

    public function enviar(int $notificacionId, array $mensaje): array
    {
        $numero = (int) DB::table('notificaciones.intentos')->where('notificacion_id', $notificacionId)->max('numero_intento') + 1;
        $intentoId = DB::table('notificaciones.intentos')->insertGetId(['notificacion_id' => $notificacionId, 'numero_intento' => $numero, 'estado' => 'PROCESANDO', 'proveedor' => 'MICROSOFT_GRAPH', 'iniciado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        try {
            $resultado = $this->transport->enviar($mensaje);
            DB::table('notificaciones.intentos')->where('id', $intentoId)->update([
                'estado' => $resultado['ok'] ? 'ENVIADO' : 'ERROR', 'http_status' => $resultado['http_status'] ?? null,
                'respuesta_sanitizada' => json_encode($resultado['respuesta'] ?? []), 'mensaje_error' => $resultado['ok'] ? null : data_get($resultado, 'respuesta.mensaje'),
                'finalizado_at' => now(), 'updated_at' => now(),
            ]);

            return $resultado;
        } catch (Throwable $e) {
            DB::table('notificaciones.intentos')->where('id', $intentoId)->update(['estado' => 'ERROR', 'mensaje_error' => $e->getMessage(), 'finalizado_at' => now(), 'updated_at' => now()]);
            throw $e;
        }
    }

    public function enviarCampania(int $destinatarioId, array $mensaje): array
    {
        $numero = (int) DB::table('notificaciones.campania_intentos')->where('destinatario_id', $destinatarioId)->max('numero_intento') + 1;
        $intentoId = DB::table('notificaciones.campania_intentos')->insertGetId([
            'destinatario_id' => $destinatarioId, 'numero_intento' => $numero, 'estado' => 'PROCESANDO',
            'proveedor' => 'MICROSOFT_GRAPH', 'iniciado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $resultado = $this->transport->enviar($mensaje);
            DB::table('notificaciones.campania_intentos')->where('id', $intentoId)->update([
                'estado' => ($resultado['ok'] ?? false) ? 'ENVIADO' : 'ERROR',
                'http_status' => $resultado['http_status'] ?? null,
                'respuesta_sanitizada' => json_encode($resultado['respuesta'] ?? []),
                'mensaje_error' => ($resultado['ok'] ?? false) ? null : data_get($resultado, 'respuesta.mensaje'),
                'finalizado_at' => now(), 'updated_at' => now(),
            ]);

            return $resultado;
        } catch (Throwable $e) {
            DB::table('notificaciones.campania_intentos')->where('id', $intentoId)->update([
                'estado' => 'ERROR', 'mensaje_error' => $e->getMessage(), 'finalizado_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }
    }
}
