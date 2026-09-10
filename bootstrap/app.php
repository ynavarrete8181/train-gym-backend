<?php

use App\Http\Middleware\AutenticarTokenBase;
use App\Http\Middleware\AutorizarFuncionBase;
use App\Services\Logs\LogSistemaService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'base.auth' => AutenticarTokenBase::class,
            'base.permiso' => AutorizarFuncionBase::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Cualquier excepción no controlada queda guardada en logs.eventos/logs.excepciones,
        // visible desde Auditoría > Errores del sistema, además del storage/logs/laravel.log de siempre.
        $exceptions->report(function (Throwable $e): void {
            try {
                app(LogSistemaService::class)->excepcion(request(), $e);
            } catch (Throwable) {
                // Nunca dejar que un fallo al loguear rompa el manejo de la excepción original.
            }
        });
    })->create();
