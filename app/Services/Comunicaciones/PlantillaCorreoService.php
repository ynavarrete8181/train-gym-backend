<?php

namespace App\Services\Comunicaciones;

use Illuminate\Support\Facades\DB;

class PlantillaCorreoService
{
    public function render(string $codigo, array $variables): array
    {
        $plantilla = DB::table('comunicaciones.plantillas')
            ->where('codigo', $codigo)
            ->where('activa', true)
            ->first();

        $asunto = $plantilla?->asunto ?? 'Notificación Revive';
        $cuerpo = $plantilla?->cuerpo ?? '';

        foreach ($variables as $clave => $valor) {
            $asunto = str_replace('{' . $clave . '}', (string) $valor, $asunto);
            $cuerpo = str_replace('{' . $clave . '}', (string) $valor, $cuerpo);
        }

        return [
            'codigo' => $codigo,
            'asunto' => $asunto,
            'mensaje' => $cuerpo,
        ];
    }
}
