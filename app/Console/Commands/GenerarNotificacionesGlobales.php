<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\NotificacionGlobalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerarNotificacionesGlobales extends Command
{
    protected $signature = 'notificaciones:globales {--fecha= : Fecha a procesar en formato YYYY-MM-DD}';

    protected $description = 'Genera notificaciones globales del gimnasio';

    public function handle(NotificacionGlobalService $service): int
    {
        $fecha = $this->option('fecha')
            ? Carbon::parse((string) $this->option('fecha'))->startOfDay()
            : now()->startOfDay();

        $resultado = $service->generar($fecha);

        $this->info(sprintf(
            'Notificaciones generadas: %s pagos pendientes, %s recordatorios de reserva, %s membresias por vencer, %s clientes ausentes.',
            $resultado['pagos_pendientes'],
            $resultado['recordatorios_reserva'],
            $resultado['membresias_por_vencer'],
            $resultado['clientes_ausentes']
        ));

        return self::SUCCESS;
    }
}
