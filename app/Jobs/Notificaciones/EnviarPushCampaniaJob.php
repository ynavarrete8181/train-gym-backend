<?php

namespace App\Jobs\Notificaciones;

use App\Services\Notificaciones\CampaniaEstadoService;
use App\Services\Notificaciones\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class EnviarPushCampaniaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $destinatarioId)
    {
        $this->onQueue('push');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ExpoPushService $push, CampaniaEstadoService $estadoService): void
    {
        $destinatario = DB::table('notificaciones.campania_destinatarios')->find($this->destinatarioId);
        if (! $destinatario || ! $destinatario->usuario_id) return;

        $campania = DB::table('notificaciones.campanias')->find($destinatario->campania_id);
        if (! $campania) return;

        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_push' => 'PROCESANDO', 'updated_at' => now(),
        ]);

        $tokens = DB::table('notificaciones.dispositivos_push')
            ->where('usuario_id', $destinatario->usuario_id)
            ->where('activo', true)
            ->pluck('token');

        if ($tokens->isEmpty()) {
            DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
                'estado_push' => 'ERROR', 'error_push' => 'El usuario no tiene un dispositivo habilitado para notificaciones push.', 'updated_at' => now(),
            ]);
            $estadoService->actualizarDestinatario($this->destinatarioId);
            return;
        }

        $cuerpo = trim((string) ($campania->cuerpo_texto ?: strip_tags((string) $campania->cuerpo_html)));
        $errores = [];
        $enviados = 0;

        foreach ($tokens as $token) {
            $resultado = $push->enviar((string) $token, (string) $campania->asunto, mb_substr($cuerpo, 0, 220), [
                'tipo' => 'COMUNICADO',
                'campania_id' => (int) $campania->id,
            ]);

            if ($resultado['ok'] ?? false) {
                $enviados++;
            } else {
                $errores[] = data_get($resultado, 'respuesta.data.message', 'El proveedor push rechazó el envío.');
            }
        }

        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_push' => $enviados > 0 ? 'ENVIADA' : 'ERROR',
            'error_push' => $enviados > 0 ? null : implode(' | ', array_unique($errores)),
            'push_at' => $enviados > 0 ? now() : null,
            'updated_at' => now(),
        ]);
        $estadoService->actualizarDestinatario($this->destinatarioId);
    }

    public function failed(?Throwable $e): void
    {
        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_push' => 'ERROR', 'error_push' => $e?->getMessage(), 'updated_at' => now(),
        ]);
        app(CampaniaEstadoService::class)->actualizarDestinatario($this->destinatarioId);
    }
}
