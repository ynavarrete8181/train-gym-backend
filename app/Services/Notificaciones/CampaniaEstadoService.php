<?php

namespace App\Services\Notificaciones;

use Illuminate\Support\Facades\DB;

class CampaniaEstadoService
{
    private const EN_PROCESO = ['PENDIENTE', 'EN_COLA', 'PROCESANDO'];

    public function actualizarDestinatario(int $destinatarioId): void
    {
        $destinatario = DB::table('notificaciones.campania_destinatarios')->find($destinatarioId);
        if (! $destinatario) {
            return;
        }

        $estados = collect([
            $destinatario->estado_interno,
            $destinatario->estado_correo,
            $destinatario->estado_push,
            $destinatario->estado_app,
        ])->reject(fn ($estado) => $estado === 'OMITIDO');

        if ($estados->isEmpty()) {
            $estado = 'ERROR';
        } elseif ($estados->contains(fn ($estado) => in_array($estado, self::EN_PROCESO, true))) {
            $estado = 'PROCESANDO';
        } elseif ($estados->contains('ERROR')) {
            $estado = 'ERROR';
        } else {
            $estado = 'ENVIADA';
        }

        DB::table('notificaciones.campania_destinatarios')->where('id', $destinatarioId)->update([
            'estado' => $estado,
            'enviado_at' => $estado === 'ENVIADA' ? now() : $destinatario->enviado_at,
            'updated_at' => now(),
        ]);

        $this->actualizarCampania((int) $destinatario->campania_id);
    }

    public function actualizarCampania(int $campaniaId): void
    {
        $conteos = DB::table('notificaciones.campania_destinatarios')->where('campania_id', $campaniaId)
            ->selectRaw("COUNT(*) FILTER (WHERE estado = 'ENVIADA') enviados, COUNT(*) FILTER (WHERE estado = 'ERROR') errores, COUNT(*) FILTER (WHERE estado IN ('PENDIENTE','EN_COLA','PROCESANDO')) pendientes")
            ->first();

        $pendientes = (int) ($conteos->pendientes ?? 0);
        $errores = (int) ($conteos->errores ?? 0);

        DB::table('notificaciones.campanias')->where('id', $campaniaId)->update([
            'total_enviados' => (int) ($conteos->enviados ?? 0),
            'total_errores' => $errores,
            'estado' => $pendientes > 0 ? 'PROCESANDO' : ($errores > 0 ? 'COMPLETADA_CON_ERRORES' : 'COMPLETADA'),
            'finalizada_at' => $pendientes > 0 ? null : now(),
            'updated_at' => now(),
        ]);
    }
}
