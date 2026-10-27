<?php

use App\Http\Middleware\AutenticarTokenBase;
use App\Http\Middleware\AsignarRequestId;
use App\Http\Middleware\AuditarOperacionGlobal;
use App\Http\Middleware\AutorizarFuncionBase;
use App\Services\Logs\LogSistemaService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            AsignarRequestId::class,
            AuditarOperacionGlobal::class,
        ]);

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
            // Diagnóstico independiente de PostgreSQL y del canal LOG_CHANNEL.
            // Nunca registrar el mensaje de la excepción, SQL, tokens ni payloads.
            try {
                $request = request();
                $diagnostico = [
                    'evento' => 'revive_excepcion_global',
                    'request_id' => $request->attributes->get('request_id'),
                    'metodo' => $request->method(),
                    'ruta' => $request->path(),
                    'clase' => $e::class,
                    'archivo' => basename($e->getFile()),
                    'linea' => $e->getLine(),
                    'sqlstate' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null,
                    'codigo_pg' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null,
                ];
                $json = json_encode($diagnostico, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json !== false) {
                    file_put_contents('php://stderr', '[REVIVE_ERROR] '.$json.PHP_EOL, FILE_APPEND);
                }
            } catch (Throwable) {
                // El diagnóstico nunca debe ocultar la excepción original.
            }

            try {
                app(LogSistemaService::class)->excepcion(request(), $e);
            } catch (Throwable $falloRegistro) {
                try {
                    file_put_contents(
                        'php://stderr',
                        '[REVIVE_ERROR] registro_excepcion_fallido '.json_encode(['clase' => get_class($falloRegistro), 'archivo' => basename($falloRegistro->getFile()), 'linea' => $falloRegistro->getLine()], JSON_UNESCAPED_UNICODE).PHP_EOL,
                        FILE_APPEND
                    );
                } catch (Throwable) {
                    // No reemplazar el error original.
                }
            }
        });
    })->create();
