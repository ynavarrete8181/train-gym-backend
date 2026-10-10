<?php

namespace App\Http\Middleware;

use App\Services\Auditoria\AuditoriaServicio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditarOperacionGlobal
{
    private const METODOS_ESCRITURA = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly AuditoriaServicio $auditoria,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array(mb_strtoupper($request->method()), self::METODOS_ESCRITURA, true)) {
            return $response;
        }

        // La auditoría funcional registra únicamente operaciones exitosas.
        // Errores y excepciones pertenecen al esquema logs.
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        // Login/logout se gestionan explícitamente en auditoria.accesos.
        if ($this->esRutaAutenticacion($request)) {
            return $response;
        }

        if (! $request->user()) {
            return $response;
        }

        // Si un servicio de negocio ya registró una auditoría detallada durante
        // este request, no generamos un evento genérico duplicado.
        if ($request->attributes->get('revive_auditoria_registrada') === true) {
            return $response;
        }

        $ruta = $request->route();
        $nombreRuta = $ruta?->getName();
        $uri = $ruta?->uri() ?: $request->path();

        $this->auditoria->registrar([
            'modulo' => $this->resolverModulo($request),
            'accion' => 'OPERACION_HTTP',
            'descripcion' => sprintf(
                '%s %s ejecutado correctamente%s.',
                mb_strtoupper($request->method()),
                $uri,
                $nombreRuta ? " ({$nombreRuta})" : ''
            ),
            'datos_despues' => [
                'metodo' => mb_strtoupper($request->method()),
                'ruta' => $uri,
                'nombre_ruta' => $nombreRuta,
                'status_http' => $response->getStatusCode(),
            ],
        ]);

        return $response;
    }

    private function esRutaAutenticacion(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return str_contains($path, 'auth/login')
            || str_contains($path, 'auth/logout')
            || str_contains($path, 'auth/microsoft');
    }

    private function resolverModulo(Request $request): string
    {
        $segmentos = array_values(array_filter(explode('/', trim($request->path(), '/'))));

        $indiceBase = array_search('base', $segmentos, true);
        if ($indiceBase !== false && isset($segmentos[$indiceBase + 1])) {
            return mb_strtolower($segmentos[$indiceBase + 1]);
        }

        $indiceGimnasio = array_search('gimnasio', $segmentos, true);
        if ($indiceGimnasio !== false && isset($segmentos[$indiceGimnasio + 1])) {
            return mb_strtolower($segmentos[$indiceGimnasio + 1]);
        }

        return 'sistema';
    }
}
