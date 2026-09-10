<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function exito(string $mensaje, mixed $datos = null, array $meta = [], int $codigo = 200): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'mensaje' => $mensaje,
            'datos' => $datos,
            'meta' => (object) $meta,
        ], $codigo);
    }

    public static function error(string $mensaje, array $errores = [], int $codigo = 422): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'mensaje' => $mensaje,
            'errores' => (object) $errores,
        ], $codigo);
    }
}
