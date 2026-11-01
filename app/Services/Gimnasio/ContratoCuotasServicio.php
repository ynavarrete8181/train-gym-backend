<?php

namespace App\Services\Gimnasio;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calendario contractual; no emite comprobantes ni registra pagos.
 * Debe ejecutarse dentro de la transacción de asignación.
 */
class ContratoCuotasServicio
{
    public function programar(int $membresiaId, int $cantidad, string $fechaPrimerPago): void
    {
        if ($cantidad < 1 || $cantidad > 36) {
            throw ValidationException::withMessages([
                'cantidad_periodos_contratados' => 'La contratación admite entre 1 y 36 períodos.',
            ]);
        }

        $membresia = DB::table('membresias.membresias')
            ->where('id', $membresiaId)->lockForUpdate()->first();

        if (! $membresia || $membresia->cantidad_periodos_contratados !== null) {
            throw ValidationException::withMessages([
                'membresia_id' => 'La membresía no existe o ya tiene calendario contractual.',
            ]);
        }

        $plan = DB::table('membresias.planes')->where('id', $membresia->plan_id)->first();
        $modalidad = $membresia->modalidad_id
            ? DB::table('membresias.plan_modalidades')->where('id', $membresia->modalidad_id)->first()
            : null;
        $tipo = $modalidad?->tipo_duracion ?? $plan?->tipo_duracion;
        $duracion = (int) ($modalidad?->duracion ?? $plan?->duracion ?? 0);

        if ($duracion < 1 || ! in_array($tipo, ['DIAS', 'SEMANAS', 'MESES', 'ANIOS'], true)) {
            throw ValidationException::withMessages(['plan_id' => 'El plan no tiene una duración válida.']);
        }

        $primerPeriodo = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $membresiaId)->where('numero_periodo', 1)->first();

        if (! $primerPeriodo) {
            throw ValidationException::withMessages(['membresia_id' => 'Falta el período inicial.']);
        }

        $fechaInicio = Carbon::parse($primerPeriodo->fecha_inicio)->startOfDay();
        $primerVencimiento = Carbon::parse($fechaPrimerPago)->startOfDay();
        if ($primerVencimiento->lt($fechaInicio)) {
            throw ValidationException::withMessages([
                'fecha_primer_pago' => 'La fecha del primer pago no puede ser anterior al inicio de la membresía.',
            ]);
        }

        $desfasePago = $fechaInicio->diffInDays($primerVencimiento);
        $precio = round((float) $primerPeriodo->precio, 2);
        $inicio = $fechaInicio->copy();
        $fin = Carbon::parse($primerPeriodo->fecha_fin)->startOfDay();

        for ($numero = 1; $numero <= $cantidad; $numero++) {
            if ($numero > 1) {
                $inicio = $fin->copy()->addDay();
                $fin = match ($tipo) {
                    'DIAS' => $inicio->copy()->addDays($duracion)->subDay(),
                    'SEMANAS' => $inicio->copy()->addWeeks($duracion)->subDay(),
                    'MESES' => $inicio->copy()->addMonthsNoOverflow($duracion)->subDay(),
                    'ANIOS' => $inicio->copy()->addYears($duracion)->subDay(),
                };

                $periodoId = DB::table('membresias.membresia_periodos')->insertGetId([
                    'membresia_id' => $membresiaId,
                    'numero_periodo' => $numero,
                    'fecha_inicio' => $inicio->toDateString(),
                    'fecha_fin' => $fin->toDateString(),
                    'precio' => $precio,
                    'estado' => 'PROGRAMADO',
                    'venta_id' => null,
                    'generado_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $periodoId = (int) $primerPeriodo->id;
            }

            DB::table('membresias.membresia_cuotas')->insert([
                'membresia_id' => $membresiaId,
                'periodo_id' => $periodoId,
                'numero_cuota' => $numero,
                'monto' => $precio,
                'fecha_programada_emision' => $inicio->toDateString(),
                'fecha_vencimiento' => $inicio->copy()->addDays($desfasePago)->toDateString(),
                'estado' => 'PROGRAMADA',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('membresias.membresias')->where('id', $membresiaId)->update([
            'cantidad_periodos_contratados' => $cantidad,
            'fecha_fin' => $fin->toDateString(),
            // El nuevo modelo se emite por cuota, nunca por el scheduler heredado.
            'generar_venta_automatica' => false,
            'proxima_fecha_cobro' => null,
            'updated_at' => now(),
        ]);
    }

    public function vincularPrimeraVenta(int $membresiaId, int $ventaId, float $importeNeto): void
    {
        $cuota = DB::table('membresias.membresia_cuotas')
            ->where('membresia_id', $membresiaId)
            ->where('numero_cuota', 1)
            ->lockForUpdate()->first();

        if (! $cuota || $cuota->venta_id !== null) {
            throw ValidationException::withMessages(['membresia_id' => 'La primera cuota ya fue emitida o no existe.']);
        }

        DB::table('membresias.membresia_cuotas')->where('id', $cuota->id)->update([
            'monto' => round($importeNeto, 2),
            'venta_id' => $ventaId,
            'estado' => 'EMITIDA',
            'emitida_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
