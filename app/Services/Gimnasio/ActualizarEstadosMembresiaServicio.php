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
        $hoy = Carbon::parse($fecha ?: now('America/Guayaquil')->toDateString())->startOfDay();
        $vencidas = 0;
        $asignacionesFinalizadas = 0;

        $ids = DB::table('membresias.membresias')
            ->whereNotIn('estado', ['CANCELADA', 'VENCIDA', 'CONGELADA'])
            ->whereRaw("fecha_fin + (COALESCE(dias_gracia, 0) * INTERVAL '1 day') < ?", [$hoy->toDateString()])
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $hoy, &$vencidas, &$asignacionesFinalizadas): void {
                $antes = DB::table('membresias.membresias')->where('id', $id)->lockForUpdate()->first();
                if (! $antes || in_array($antes->estado, ['CANCELADA', 'VENCIDA', 'CONGELADA'], true)) {
                    return;
                }

                // Verificar de nuevo dentro del bloqueo: otra operación pudo
                // ampliar vigencia, agregar gracia o congelar la membresía.
                if (! $antes->fecha_fin
                    || Carbon::parse($antes->fecha_fin)
                        ->addDays(max(0, (int) ($antes->dias_gracia ?? 0)))
                        ->startOfDay()
                        ->greaterThanOrEqualTo($hoy)) {
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

                // Un pase vuelve a venderse otro día y conserva su entrenador y horario.
                $esPase = DB::table('membresias.planes')->where('id', $antes->plan_id)
                    ->value('tipo_producto') === 'PASE_DIARIO';
                $finalizadas = $esPase ? 0 : DB::table('entrenamiento.asignaciones_entrenador_cliente')
                    ->where('membresia_id', $id)
                    ->where('estado', 'ACTIVO')
                    ->update([
                        'estado' => 'FINALIZADO',
                        'fecha_fin' => now('America/Guayaquil')->toDateString(),
                        'updated_at' => now(),
                    ]);

                $despues = DB::table('membresias.membresias')->where('id', $id)->first();
                $this->auditar('membresias', 'VENCER_AUTOMATICAMENTE', 'membresias.membresias', (int) $id, $antes, $despues);
                $vencidas++;
                $asignacionesFinalizadas += $finalizadas;
            });
        }

        // Garantía adicional: aunque el emisor de cuotas no llegue a correr,
        // un período renovado sin pago no conserva acceso por fecha ampliada.
        $renovacionesSinPago = DB::table('membresias.membresias as m')
            ->join('membresias.membresia_periodos as periodo', 'periodo.membresia_id', '=', 'm.id')
            ->leftJoin('ventas.ventas as venta', 'venta.id', '=', 'periodo.venta_id')
            ->where('m.estado', 'ACTIVA')
            ->where('periodo.renovacion_manual', true)
            ->where('periodo.fecha_inicio', '<=', $hoy->toDateString())
            ->whereNotIn('periodo.estado', ['ACTIVA', 'CANCELADA', 'VENCIDA'])
            ->where(function ($query): void {
                $query->whereNull('venta.id')->orWhere('venta.estado', '!=', 'PAGADA');
            })
            ->distinct()->pluck('m.id');
        $bloqueadasRenovacion = 0;
        foreach ($renovacionesSinPago as $membresiaId) {
            DB::transaction(function () use ($membresiaId, $hoy, &$bloqueadasRenovacion): void {
                $m = DB::table('membresias.membresias')
                    ->where('id', $membresiaId)->lockForUpdate()->first();
                if (! $m || $m->estado !== 'ACTIVA') return;
                $periodo = DB::table('membresias.membresia_periodos')
                    ->where('membresia_id', $membresiaId)
                    ->where('renovacion_manual', true)
                    ->where('fecha_inicio', '<=', $hoy->toDateString())
                    ->whereNotIn('estado', ['ACTIVA', 'CANCELADA', 'VENCIDA'])
                    ->orderByDesc('numero_periodo')->lockForUpdate()->first();
                if (! $periodo) return;
                if ($periodo->venta_id
                    && DB::table('ventas.ventas')->where('id', $periodo->venta_id)
                        ->where('estado', 'PAGADA')->exists()) return;
                $estado = $this->estados->aplicar(['estado' => 'PENDIENTE_PAGO'], 'MEMBRESIA');
                DB::table('membresias.membresias')->where('id', $membresiaId)->update([
                    'estado' => $estado['estado'],
                    'estado_id' => $estado['estado_id'] ?? null,
                    'updated_at' => now(),
                ]);
                $this->auditar('membresias', 'SUSPENDER_ACCESO_RENOVACION_SIN_PAGO',
                    'membresias.membresias', (int) $membresiaId, $m,
                    DB::table('membresias.membresias')->where('id', $membresiaId)->first());
                $bloqueadasRenovacion++;
            });
        }

        return [
            'procesadas' => $ids->count(),
            'bloqueadas_renovacion_sin_pago' => $bloqueadasRenovacion,
            'vencidas' => $vencidas,
            'asignaciones_finalizadas' => $asignacionesFinalizadas,
            'fecha' => $hoy->toDateString(),
        ];
    }
}
