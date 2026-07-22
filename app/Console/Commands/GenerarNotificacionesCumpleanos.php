<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\NotificacionGlobalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerarNotificacionesCumpleanos extends Command
{
    protected $signature = 'notificaciones:cumpleanos {--fecha= : Fecha a procesar en formato YYYY-MM-DD} {--forzar : Ignora la hora configurada}';

    protected $description = 'Genera notificaciones de cumpleanos segun la configuracion del gimnasio';

    public function handle(NotificacionGlobalService $service): int
    {
        $fecha = $this->option('fecha')
            ? Carbon::parse((string) $this->option('fecha'))->startOfDay()
            : now();

        if (!$this->option('forzar') && !$service->debeGenerarCumpleanosAhora($fecha)) {
            return self::SUCCESS;
        }

        $total = $service->generarCumpleanos($fecha->copy()->startOfDay());

        $this->info(sprintf('Notificaciones de cumpleanos generadas: %s.', $total));

        return self::SUCCESS;
    }
}
