<?php

namespace App\Jobs\Seguridad;

use App\Services\Seguridad\CargaMasivaUsuarioLoteService;
use App\Services\Seguridad\TiempoRealUsuarioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcesarCargaMasivaUsuariosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $idCarga) {}

    public function handle(CargaMasivaUsuarioLoteService $service, TiempoRealUsuarioService $tiempoReal): void
    {
        // Bloques pequeños para no concentrar creación de usuarios y notificaciones
        // en una sola ejecución del worker. Cada bloque vuelve a encolar el siguiente.
        $service->procesarBloque($this->idCarga, 10);
        $this->emitirEstado($service, $tiempoReal);
    }

    public function failed(Throwable $exception): void
    {
        $service = app(CargaMasivaUsuarioLoteService::class);
        $service->marcarErrorGeneral($this->idCarga, $exception->getMessage());
        $this->emitirEstado($service, app(TiempoRealUsuarioService::class));
    }

    private function emitirEstado(CargaMasivaUsuarioLoteService $service, TiempoRealUsuarioService $tiempoReal): void
    {
        $usuarioId = DB::table('seguridad.cargas_masivas_usuario')->where('id', $this->idCarga)->value('solicitado_por');
        if ($usuarioId) {
            $tiempoReal->emitir((int) $usuarioId, 'CARGA_MASIVA_USUARIOS', $service->estado($this->idCarga));
        }
    }
}
