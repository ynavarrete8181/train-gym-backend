<?php

namespace App\Services\Notificaciones;

use Illuminate\Support\Facades\DB;

class ConfiguracionNotificacionService
{
    public function correosPorMinuto(): int
    {
        $fallback = max(1, (int) config('notificaciones.limites.correos_por_minuto', 10));

        try {
            $configuracion = DB::table('integraciones.servicios')
                ->where('codigo', 'CORREO_ENVIAR')
                ->where('activo', true)
                ->value('configuracion');

            if (! $configuracion) {
                return $fallback;
            }

            $datos = is_array($configuracion) ? $configuracion : json_decode((string) $configuracion, true);
            $valor = (int) ($datos['correos_por_minuto'] ?? $fallback);

            return min(1000, max(1, $valor));
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
