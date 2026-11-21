<?php

namespace App\Providers;

use App\Contracts\Integraciones\CorreoTransportContract;
use App\Services\Integraciones\MicrosoftGraphCorreoTransport;
use App\Services\Integraciones\CorreoRedirigidoTransport;
use App\Services\Integraciones\GmailApiCorreoTransport;
use App\Services\Integraciones\SmtpCorreoTransport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CorreoTransportContract::class, function ($app) {
            $transporte = match (strtoupper((string) config('services.revive_mail.transport', 'GRAPH'))) {
                'SMTP' => $app->make(SmtpCorreoTransport::class),
                'GMAIL_API' => $app->make(GmailApiCorreoTransport::class),
                default => $app->make(MicrosoftGraphCorreoTransport::class),
            };

            // Modo desarrollo (REVIVE_CORREO_MODO_DESARROLLO=true, solo en el .env local):
            // todo correo se desvía a REVIVE_CORREO_REDIRIGIR_A, o no se envía si falta.
            // Producción no define esa variable y envía normal.
            return config('services.revive_mail.modo_desarrollo')
                ? new CorreoRedirigidoTransport($transporte, config('services.revive_mail.redirigir_a'))
                : $transporte;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
