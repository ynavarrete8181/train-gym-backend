<?php

use App\Http\Middleware\RequestIdMiddleware;
use App\Services\Logs\LogSistemaService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            RequestIdMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $exception): void {
            try {
                app(LogSistemaService::class)->excepcion(request(), $exception);
            } catch (\Throwable) {
                //
            }
        });
    })->create();
