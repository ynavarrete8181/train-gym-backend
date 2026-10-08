<?php

namespace App\Providers;

use App\Contracts\Integraciones\CorreoTransportContract;
use App\Services\Integraciones\MicrosoftGraphCorreoTransport;
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
            return strtoupper((string) config('services.revive_mail.transport', 'GRAPH')) === 'SMTP'
                ? $app->make(SmtpCorreoTransport::class)
                : $app->make(MicrosoftGraphCorreoTransport::class);
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
