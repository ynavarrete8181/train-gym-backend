<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\NotificacionService;
use Illuminate\Console\Command;

class DespacharNotificacionesPush extends Command
{
    protected $signature = 'notificaciones:despachar-push {--limit=100 : Maximo de destinatarios a procesar}';

    protected $description = 'Despacha notificaciones push pendientes a dispositivos registrados';

    public function handle(NotificacionService $service): int
    {
        $resultado = $service->despacharPushPendientes((int) $this->option('limit'));

        $this->info(sprintf(
            'Push procesadas: %s enviadas, %s sin dispositivo, %s errores.',
            $resultado['enviadas'],
            $resultado['sin_dispositivo'],
            $resultado['errores']
        ));

        return self::SUCCESS;
    }
}
