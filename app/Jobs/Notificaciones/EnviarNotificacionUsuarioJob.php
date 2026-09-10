<?php

namespace App\Jobs\Notificaciones;

use App\Models\User;
use App\Services\Notificaciones\ActivacionUsuarioService;
use App\Services\Notificaciones\ConfiguracionNotificacionService;
use App\Services\Notificaciones\ContenidoCorreoService;
use App\Services\Notificaciones\CorreoService;
use App\Services\Notificaciones\VariablesAccesoUsuarioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class EnviarNotificacionUsuarioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1000;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $notificacionId)
    {
        $this->onQueue('correos');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        CorreoService $correo,
        ActivacionUsuarioService $activacion,
        VariablesAccesoUsuarioService $variablesService,
        ContenidoCorreoService $contenido,
        ConfiguracionNotificacionService $configuracion,
    ): void {
        $limite = $configuracion->correosPorMinuto();
        $claveLimite = 'notificaciones:correos:global';

        if (RateLimiter::tooManyAttempts($claveLimite, $limite)) {
            $this->release(max(1, RateLimiter::availableIn($claveLimite)));
            return;
        }

        RateLimiter::hit($claveLimite, 60);

        $notificacion = DB::table('notificaciones.notificaciones')->find($this->notificacionId);
        if (! $notificacion) {
            return;
        }

        DB::table('notificaciones.notificaciones')->where('id', $this->notificacionId)->update([
            'estado' => 'PROCESANDO',
            'updated_at' => now(),
        ]);

        $usuario = User::findOrFail($notificacion->usuario_id);
        $plantilla = DB::table('notificaciones.plantillas')->find($notificacion->plantilla_id);
        $usadas = $contenido->variablesEn($plantilla->asunto, $plantilla->cuerpo_html, $plantilla->cuerpo_texto ?? '');
        $desconocidas = array_diff($usadas, ContenidoCorreoService::VARIABLES_ACCESO);

        if ($desconocidas) {
            throw new \RuntimeException('La plantilla contiene variables no admitidas: '.implode(', ', $desconocidas).'.');
        }
        if (! in_array('url_activacion', $usadas, true)) {
            throw new \RuntimeException('La plantilla de acceso debe incluir {{url_activacion}}.');
        }

        $enlace = $activacion->crearEnlace($usuario);
        $variables = $variablesService->obtener($usuario, $enlace['url'], $enlace['expira'], $enlace['codigo']);
        $render = fn (string $texto) => preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', fn ($m) => e($variables[$m[1]] ?? $m[0]), $texto);
        $mensaje = [
            'para' => $notificacion->correo_destino,
            'asunto' => $render($plantilla->asunto),
            'html' => $render($plantilla->cuerpo_html),
            'texto' => $render($plantilla->cuerpo_texto ?? ''),
        ];

        DB::table('notificaciones.notificaciones')->where('id', $this->notificacionId)->update([
            'asunto_enviado' => $mensaje['asunto'],
            'cuerpo_html_enviado' => $mensaje['html'],
            'cuerpo_texto_enviado' => $mensaje['texto'],
            'variables_enviadas' => json_encode($variables),
            'updated_at' => now(),
        ]);

        $resultado = $correo->enviar($this->notificacionId, $mensaje);
        if (! ($resultado['ok'] ?? false)) {
            throw new \RuntimeException(data_get($resultado, 'respuesta.mensaje', 'El proveedor rechazó el correo.'));
        }

        DB::table('notificaciones.notificaciones')->where('id', $this->notificacionId)->update([
            'estado' => 'ENVIADA',
            'enviado_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        DB::table('notificaciones.notificaciones')->where('id', $this->notificacionId)->update([
            'estado' => 'ERROR',
            'updated_at' => now(),
        ]);
    }
}
