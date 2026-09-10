<?php

namespace App\Services\Seguridad;

use App\Events\NavegacionSistemaActualizada;
use Illuminate\Support\Facades\Log;
use Throwable;

class TiempoRealNavegacionService
{
    public function emitir(string $codigo): void
    {
        try {
            broadcast(new NavegacionSistemaActualizada($codigo));
        } catch (Throwable $error) {
            Log::warning('No se pudo emitir la actualización de navegación.', [
                'codigo' => $codigo,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
