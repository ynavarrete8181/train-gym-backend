<?php

namespace App\Jobs\Notificaciones;

use App\Services\Notificaciones\CampaniaEstadoService;
use App\Services\Seguridad\AvisoUsuarioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class EnviarInternoCampaniaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public readonly int $destinatarioId)
    {
        $this->onQueue('default');
    }

    public function handle(AvisoUsuarioService $avisos, CampaniaEstadoService $estadoService): void
    {
        $destinatario = DB::table('notificaciones.campania_destinatarios')->find($this->destinatarioId);
        if (! $destinatario || ! $destinatario->usuario_id) return;

        $campania = DB::table('notificaciones.campanias')->find($destinatario->campania_id);
        if (! $campania) return;

        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_interno' => 'PROCESANDO', 'updated_at' => now(),
        ]);

        $mensaje = trim((string) ($campania->cuerpo_texto ?: strip_tags((string) $campania->cuerpo_html)));
        $avisos->registrar(
            (int) $destinatario->usuario_id,
            'COMUNICADO',
            (string) $campania->asunto,
            mb_substr($mensaje, 0, 500),
            null,
            'COMUNICADO',
            (int) $campania->id,
        );

        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_interno' => 'ENVIADA', 'error_interno' => null, 'interno_at' => now(), 'updated_at' => now(),
        ]);
        $estadoService->actualizarDestinatario($this->destinatarioId);
    }

    public function failed(?Throwable $e): void
    {
        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_interno' => 'ERROR', 'error_interno' => $e?->getMessage(), 'updated_at' => now(),
        ]);
        app(CampaniaEstadoService::class)->actualizarDestinatario($this->destinatarioId);
    }
}
