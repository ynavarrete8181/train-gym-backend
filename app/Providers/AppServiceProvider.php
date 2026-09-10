<?php

namespace App\Providers;

use App\Contracts\Integraciones\CorreoTransportContract;
use App\Services\Integraciones\MicrosoftGraphCorreoTransport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CorreoTransportContract::class, MicrosoftGraphCorreoTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
