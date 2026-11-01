<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calendario contractual; no emite comprobantes ni registra pagos.
 * Debe ejecutarse dentro de la transacción de asignación.
 */
class ContratoCuotasServicio
{
    use RegistraAuditoria;

    /**
     * Vencimiento fijo: misma fecha base, desplazada por meses calendario.
     * El día 29/30/31 se ajusta solo en meses más cortos.
     */
    public static function vencimientoMensual(string $primeraFechaPago, int $indiceCuota): string
    {
        if ($indiceCuota < 0) {
            throw new \InvalidArgumentException('El índice de cuota debe ser positivo.');
        }

        return Carbon::parse($primeraFechaPago)->startOfDay()
            ->addMonthsNoOverflow($indiceCuota)->toDateString();
    }

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
        // Captura de las condiciones vigentes al contratar: los cambios
        // posteriores al catálogo no modifican los compromisos del deportista.
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

        // La primera fecha de pago fija el día de vencimiento de cada mes.
        // Los meses cortos usan su último día (29/30/31); cada cuota se
        // calcula desde la fecha original para evitar deriva acumulada.
        $diaPago = (int) $primerVencimiento->day;
        $precio = round((float) $primerPeriodo->precio, 2);

        // Snapshot explícito de los atributos que afectan el servicio,
        // la vigencia y el importe. No se reconstruye desde el catálogo.
        $servicios = DB::table('membresias.plan_servicios as ps')
            ->join('servicios.servicios as s', 's.id', '=', 'ps.servicio_id')
            ->where('ps.plan_id', $membresia->plan_id)
            ->where('ps.activo', true)
            ->orderBy('s.id')
            ->get(['s.id', 's.nombre'])
            ->map(fn ($servicio) => [
                'id' => (int) $servicio->id,
                'nombre' => (string) $servicio->nombre,
            ])->all();

        $condiciones = [
            'version' => 1,
            'servicios_incluidos' => $servicios,
            'capturado_at' => now()->toIso8601String(),
            'plan_id' => (int) $membresia->plan_id,
            'plan_nombre' => (string) ($plan?->nombre ?? ''),
            'modalidad_id' => $modalidad?->id ? (int) $modalidad->id : null,
            'modalidad_nombre' => $modalidad?->nombre,
            'tipo_producto' => $plan?->tipo_producto,
            'requiere_pago' => (bool) ($plan?->requiere_pago ?? true),
            'requiere_entrenador' => (bool) ($plan?->requiere_entrenador ?? false),
            'renovable' => (bool) ($plan?->renovable ?? false),
            'modelo_cobro_modalidad' => $modalidad?->modelo_cobro,
            'momento_cobro_modalidad' => $modalidad?->momento_cobro,
            'sede_id' => (int) $membresia->sede_id,
            'tipo_duracion' => $tipo,
            'duracion' => $duracion,
            'dias_por_semana' => $modalidad?->dias_por_semana,
            'usos_por_semana' => $modalidad?->usos_por_semana,
            'uso_ilimitado' => $modalidad?->uso_ilimitado,
            'precio_periodo' => number_format($precio, 2, '.', ''),
            'cantidad_periodos' => $cantidad,
            'total_bruto_referencial' => number_format(round($precio * $cantidad, 2), 2, '.', ''),
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_primer_pago' => $primerVencimiento->toDateString(),
            'dia_pago' => $diaPago,
            'programacion_vencimientos' => 'DIA_FIJO_MENSUAL',
        ];

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
                'fecha_vencimiento' => self::vencimientoMensual($fechaPrimerPago, $numero - 1),
                'estado' => 'PROGRAMADA',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('membresias.membresias')->where('id', $membresiaId)->update([
            'cantidad_periodos_contratados' => $cantidad,
            'dia_pago' => $diaPago,
            'condiciones_contratadas' => json_encode($condiciones, JSON_THROW_ON_ERROR),
            'fecha_fin' => $fin->toDateString(),
            // El nuevo modelo se emite por cuota, nunca por el scheduler heredado.
            'generar_venta_automatica' => false,
            'proxima_fecha_cobro' => null,
            'updated_at' => now(),
        ]);

        app(HistorialComercialMembresiaServicio::class)->registrarContrato(
            $membresiaId,
            'CONTRATACION',
            array_merge($condiciones, ['fecha_fin' => $fin->toDateString()]),
            'Condiciones originales de la contratación.'
        );

        $this->auditar(
            'membresias',
            'FIJAR_CONDICIONES_CONTRATADAS',
            'membresias.membresias',
            $membresiaId,
            null,
            [
                'condiciones_contratadas' => $condiciones,
                'fecha_fin' => $fin->toDateString(),
                'cantidad_periodos_contratados' => $cantidad,
            ],
            'Condiciones comerciales y deportivas congeladas al momento de contratar.'
        );
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
