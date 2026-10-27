<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use App\Services\Configuracion\EstadoCatalogoServicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ActualizarEstadosMembresiaServicio
{
    use RegistraAuditoria;

    public function __construct(private readonly EstadoCatalogoServicio $estados)
    {
    }

    public function procesar(?string $fecha = null): array
    {
        $hoy = Carbon::parse($fecha ?: now()->toDateString())->startOfDay();
        $vencidas = 0;
        $asignacionesFinalizadas = 0;

        $ids = DB::table('membresias.membresias')
            ->whereNotIn('estado', ['CANCELADA', 'VENCIDA', 'CONGELADA'])
            ->whereRaw("fecha_fin + (COALESCE(dias_gracia, 0) * INTERVAL '1 day') < ?", [$hoy->toDateString()])
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$vencidas, &$asignacionesFinalizadas): void {
                $antes = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
                if (! $antes || in_array($antes->estado, ['CANCELADA', 'VENCIDA', 'CONGELADA'], true)) {
                    return;
                }

                $estado = $this->estados->aplicar(['estado' => 'VENCIDA'], 'MEMBRESIA');
                DB::table('membresias.membresias')->where('id', $id)->update([
                    'estado' => $estado['estado'],
                    'estado_id' => $estado['estado_id'] ?? null,
                    'updated_at' => now(),
                ]);
                DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $id)
                    ->whereNotIn('estado', ['CANCELADA', 'VENCIDA'])
                    ->update(['estado' => 'VENCIDA', 'updated_at' => now()]);

                $finalizadas = DB::table('entrenamiento.asignaciones_entrenador_cliente')
                    ->where('membresia_id', $id)
                    ->where('estado', 'ACTIVO')
                    ->update([
                        'estado' => 'FINALIZADO',
                        'fecha_fin' => now()->toDateString(),
                        'updated_at' => now(),
                    ]);

                $despues = DB::table('membresias.membresias')->where('id', $id)->first();
                $this->auditar('membresias', 'VENCER_AUTOMATICAMENTE', 'membresias.membresias', (int) $id, $antes, $despues);
                $vencidas++;
                $asignacionesFinalizadas += $finalizadas;
            });
        }

        return [
            'procesadas' => $ids->count(),
            'vencidas' => $vencidas,
            'asignaciones_finalizadas' => $asignacionesFinalizadas,
            'fecha' => $hoy->toDateString(),
        ];
    }
}
