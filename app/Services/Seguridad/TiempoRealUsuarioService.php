<?php

namespace App\Services\Seguridad;

use App\Events\TiempoRealUsuarioActualizado;
use Illuminate\Support\Facades\Log;
use Throwable;

class TiempoRealUsuarioService
{
    public function emitir(?int $usuarioId, string $tipo, array $datos): void
    {
        if (! $usuarioId) {
            return;
        }

        try {
            broadcast(new TiempoRealUsuarioActualizado($usuarioId, $tipo, $datos));
        } catch (Throwable $error) {
            Log::warning('No se pudo emitir una actualización en tiempo real para el usuario.', [
                'usuario_id' => $usuarioId,
                'tipo' => $tipo,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
