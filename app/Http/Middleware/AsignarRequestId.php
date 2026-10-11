<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AsignarRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = trim((string) $request->headers->get('X-Request-ID'));

        if ($requestId === '' || mb_strlen($requestId) > 80) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
