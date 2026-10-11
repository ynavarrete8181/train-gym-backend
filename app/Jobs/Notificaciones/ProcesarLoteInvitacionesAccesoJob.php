<?php

namespace App\Jobs\Notificaciones;

use App\Services\Notificaciones\LoteInvitacionAccesoService;
use App\Services\Seguridad\TiempoRealUsuarioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcesarLoteInvitacionesAccesoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $idLote)
    {
        $this->onConnection('database');
    }

    public function handle(LoteInvitacionAccesoService $service, TiempoRealUsuarioService $tiempoReal): void
    {
        $service->procesarBloque($this->idLote, 10);
        $this->emitirEstado($service, $tiempoReal);
    }

    public function failed(Throwable $exception): void
    {
        $service = app(LoteInvitacionAccesoService::class);
        $service->marcarErrorGeneral($this->idLote, $exception->getMessage());
        $this->emitirEstado($service, app(TiempoRealUsuarioService::class));
    }

    private function emitirEstado(LoteInvitacionAccesoService $service, TiempoRealUsuarioService $tiempoReal): void
    {
        $usuarioId = DB::table('notificaciones.lotes_acceso')->where('id', $this->idLote)->value('solicitado_por');
        if ($usuarioId) {
            $tiempoReal->emitir((int) $usuarioId, 'LOTE_INVITACIONES_ACCESO', $service->estado($this->idLote));
        }
    }
}
