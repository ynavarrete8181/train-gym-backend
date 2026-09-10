<?php

namespace App\Jobs\Notificaciones;

use App\Services\Notificaciones\CampaniaEstadoService;
use App\Services\Notificaciones\ConfiguracionNotificacionService;
use App\Services\Notificaciones\CorreoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class EnviarDestinatarioCampaniaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1000;
    public int $maxExceptions = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $destinatarioId)
    {
        $this->onQueue('correos');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        CorreoService $correo,
        ConfiguracionNotificacionService $configuracion,
        CampaniaEstadoService $estadoService,
    ): void {
        $limite = $configuracion->correosPorMinuto();
        $claveLimite = 'notificaciones:correos:global';

        if (RateLimiter::tooManyAttempts($claveLimite, $limite)) {
            $this->release(max(1, RateLimiter::availableIn($claveLimite)));
            return;
        }

        RateLimiter::hit($claveLimite, 60);

        $destinatario = DB::table('notificaciones.campania_destinatarios')->find($this->destinatarioId);
        if (! $destinatario) return;

        $campania = DB::table('notificaciones.campanias')->find($destinatario->campania_id);
        if (! $campania) return;

        if (! $destinatario->correo_destino) {
            DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
                'estado_correo' => 'ERROR', 'ultimo_error' => 'El destinatario no tiene correo válido.', 'updated_at' => now(),
            ]);
            $estadoService->actualizarDestinatario($this->destinatarioId);
            return;
        }

        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_correo' => 'PROCESANDO', 'numero_intentos' => DB::raw('numero_intentos + 1'), 'updated_at' => now(),
        ]);

        $variables = is_string($destinatario->variables) ? json_decode($destinatario->variables, true) : (array) $destinatario->variables;
        $render = fn (string $texto) => preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', fn ($m) => e($variables[$m[1]] ?? $m[0]), $texto);
        $resultado = $correo->enviarCampania($this->destinatarioId, [
            'para' => $destinatario->correo_destino,
            'cc' => json_decode($campania->correos_cc ?? '[]', true),
            'cco' => json_decode($campania->correos_cco ?? '[]', true),
            'asunto' => $render($campania->asunto),
            'html' => $render($campania->cuerpo_html),
            'texto' => $render($campania->cuerpo_texto ?? ''),
        ]);

        if (! ($resultado['ok'] ?? false)) {
            throw new \RuntimeException(data_get($resultado, 'respuesta.mensaje', 'El proveedor rechazó el correo.'));
        }

        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_correo' => 'ENVIADA', 'enviado_at' => now(), 'ultimo_error' => null, 'updated_at' => now(),
        ]);
        $estadoService->actualizarDestinatario($this->destinatarioId);
    }

    public function failed(?Throwable $e): void
    {
        DB::table('notificaciones.campania_destinatarios')->where('id', $this->destinatarioId)->update([
            'estado_correo' => 'ERROR', 'ultimo_error' => $e?->getMessage(), 'updated_at' => now(),
        ]);
        app(CampaniaEstadoService::class)->actualizarDestinatario($this->destinatarioId);
    }
}
