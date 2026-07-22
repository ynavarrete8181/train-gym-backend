<?php

namespace App\Services\Comunicaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CorreoService
{
    public function enviarPlantilla(
        string $codigoPlantilla,
        string $destinatario,
        array $variables,
        ?int $actorId = null,
        array $metadata = []
    ): array {
        $render = app(PlantillaCorreoService::class)->render($codigoPlantilla, $variables);

        $envioId = DB::table('comunicaciones.envios')->insertGetId([
            'plantilla_codigo' => $codigoPlantilla,
            'canal' => 'EMAIL',
            'destinatario' => $destinatario,
            'asunto' => $render['asunto'],
            'mensaje' => $render['mensaje'],
            'estado' => 'PENDIENTE',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_id_user' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Mail::raw($render['mensaje'], function ($message) use ($destinatario, $render) {
                $message->to($destinatario)->subject($render['asunto']);
            });

            DB::table('comunicaciones.envios')
                ->where('id', $envioId)
                ->update([
                    'estado' => 'ENVIADO',
                    'enviado_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'id' => $envioId,
                'estado' => 'ENVIADO',
                'message' => 'Correo enviado correctamente.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            DB::table('comunicaciones.envios')
                ->where('id', $envioId)
                ->update([
                    'estado' => 'ERROR',
                    'error' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);

            return [
                'id' => $envioId,
                'estado' => 'ERROR',
                'message' => 'No se pudo enviar el correo. El intento quedó registrado.',
                'error' => $exception->getMessage(),
            ];
        }
    }
}
