<?php

namespace App\Services\Gimnasio;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renovación manual del calendario contractual: un período/cuota nuevo sobre
 * el MISMO contrato. No emite ventas ni cobra aquí: EmisionCuotasServicio
 * es el único responsable de generar comprobantes cuando corresponda.
 */
final class RenovacionContratoProgramadoServicio
{
    /**
     * La continuidad siempre inicia un día después del último período real.
     * Permite probar fechas sin depender de una base PostgreSQL.
     */
    public static function calcularProximoPeriodo(string $finActual, string $tipo, int $duracion): array
    {
        if (! in_array($tipo, ['DIAS', 'SEMANAS', 'MESES', 'ANIOS'], true) || $duracion < 1) {
            throw new \InvalidArgumentException('Duración de contrato no válida.');
        }
        $inicio = CarbonImmutable::parse($finActual)->addDay();
        $fin = match ($tipo) {
            'DIAS' => $inicio->addDays($duracion)->subDay(),
            'SEMANAS' => $inicio->addWeeks($duracion)->subDay(),
            'MESES' => $inicio->addMonthsNoOverflow($duracion)->subDay(),
            'ANIOS' => $inicio->addYears($duracion)->subDay(),
        };
        return ['fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString()];
    }

    public function previsualizar(int $id, bool $bloquear = false): array
    {
        $consulta = DB::table('membresias.membresias')->where('id', $id);
        $m = $bloquear ? $consulta->lockForUpdate()->first() : $consulta->first();
        if (! $m) {
            throw ValidationException::withMessages(['membresia_id' => 'Membresía no encontrada.']);
        }
        if ($m->cantidad_periodos_contratados === null) {
            throw ValidationException::withMessages([
                'membresia_id' => 'Esta membresía usa el proceso de renovación tradicional.',
            ]);
        }
        if ($m->estado !== 'ACTIVA') {
            throw ValidationException::withMessages([
                'membresia_id' => 'Para renovar, la membresía debe estar activa. Primero reactiva cualquier congelación vigente.',
            ]);
        }
        if (DB::table('membresias.membresia_suspensiones')->where('membresia_id', $id)
            ->whereIn('estado', ['PROGRAMADA', 'VIGENTE'])->exists()) {
            throw ValidationException::withMessages([
                'membresia_id' => 'Resuelve las congelaciones programadas o vigentes antes de renovar.',
            ]);
        }
        $condiciones = json_decode((string) ($m->condiciones_contratadas ?? ''), true);
        if (! is_array($condiciones) || ! ($condiciones['renovable'] ?? false)) {
            throw ValidationException::withMessages([
                'membresia_id' => 'El contrato original no autoriza renovaciones. Se requiere una nueva contratación.',
            ]);
        }
        $tipo = $condiciones['tipo_duracion'] ?? null;
        $duracion = (int) ($condiciones['duracion'] ?? 0);
        if (! in_array($tipo, ['DIAS', 'SEMANAS', 'MESES', 'ANIOS'], true) || $duracion < 1) {
            throw ValidationException::withMessages(['membresia_id' => 'Duración histórica del contrato no válida.']);
        }
        $ultimo = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $id)->orderByDesc('numero_periodo');
        $ultimo = $bloquear ? $ultimo->lockForUpdate()->first() : $ultimo->first();
        if (! $ultimo || (int) $ultimo->numero_periodo !== (int) $m->cantidad_periodos_contratados) {
            throw ValidationException::withMessages([
                'membresia_id' => 'El calendario de períodos no coincide con el contrato. Revisa el historial antes de renovar.',
            ]);
        }
        if ((int) $ultimo->numero_periodo >= 36) {
            throw ValidationException::withMessages([
                'membresia_id' => 'Alcanzó 36 períodos. Realiza una nueva contratación con condiciones actualizadas.',
            ]);
        }
        if (substr((string) $m->fecha_fin, 0, 10) !== substr((string) $ultimo->fecha_fin, 0, 10)) {
            throw ValidationException::withMessages([
                'membresia_id' => 'La vigencia no coincide con el último período. Revisa la compensación por congelación.',
            ]);
        }

        // Se renueva cuando faltan como máximo 45 días; no se facturan
        // períodos superpuestos ni se extiende el contrato indefinidamente.
        $hoy = CarbonImmutable::today('America/Guayaquil');
        $finActual = CarbonImmutable::parse($ultimo->fecha_fin);
        if ($finActual->lessThan($hoy) || $finActual->greaterThan($hoy->addDays(45))) {
            throw ValidationException::withMessages([
                'membresia_id' => 'La renovación manual se permite durante los últimos 45 días de vigencia, antes de vencer.',
            ]);
        }
        // Bloquear hasta conciliar cualquier venta emitida del contrato.
        $cuotas = DB::table('membresias.membresia_cuotas')
            ->where('membresia_id', $id)->orderBy('numero_cuota');
        $cuotas = $bloquear ? $cuotas->lockForUpdate()->get() : $cuotas->get();
        if ($cuotas->count() !== (int) $m->cantidad_periodos_contratados
            || $cuotas->pluck('periodo_id')->unique()->count() !== $cuotas->count()) {
            throw ValidationException::withMessages([
                'cuotas' => 'Las cuotas no coinciden con los períodos pactados. Solicita conciliación.',
            ]);
        }
        foreach ($cuotas as $cuota) {
            if ($cuota->estado === 'ANULADA') {
                throw ValidationException::withMessages(['cuotas' => 'Existe una cuota anulada; requiere revisión.']);
            }
            // No extender un plazo fijo si alguna cuota del acuerdo original
            // todavía no se ha emitido ni pagado. Se habilita la renovación
            // cuando todas las obligaciones pactadas están realmente pagadas.
            if (! $cuota->venta_id) {
                throw ValidationException::withMessages([
                    'cuotas' => 'Faltan cuotas por emitir y pagar. Espera su emisión y confirma todos los pagos antes de renovar.',
                ]);
            }
            $venta = DB::table('ventas.ventas')->where('id', $cuota->venta_id)->first();
            if (! $venta || $venta->estado !== 'PAGADA') {
                throw ValidationException::withMessages([
                    'cuotas' => 'Existen ventas sin pago completo. Revisa Ventas y Cartera antes de renovar.',
                ]);
            }
        }

        $proximo = self::calcularProximoPeriodo($finActual->toDateString(), $tipo, $duracion);
        $inicio = CarbonImmutable::parse($proximo['fecha_inicio']);
        $fin = CarbonImmutable::parse($proximo['fecha_fin']);
        $diaPago = (int) ($m->dia_pago ?: ($condiciones['dia_pago'] ?? 0));
        $vencimiento = ContratoCuotasServicio::primeraFechaPagoPorDia($inicio->toDateString(), $diaPago);
        if ($vencimiento > $fin->toDateString()) {
            throw ValidationException::withMessages([
                'dia_pago' => 'El próximo día de pago queda fuera del período renovado. Requiere revisión comercial.',
            ]);
        }
        $precio = round((float) ($condiciones['precio_periodo'] ?? 0), 2);
        if ($precio <= 0) {
            throw ValidationException::withMessages([
                'precio' => 'El importe contractual histórico no es válido.',
            ]);
        }
        return [
            'confirmable' => true,
            'membresia_id' => $id,
            'codigo_contrato' => $m->codigo_contrato,
            'periodo_actual' => (int) $ultimo->numero_periodo,
            'numero_periodo_nuevo' => (int) $ultimo->numero_periodo + 1,
            'vigencia_actual' => $finActual->toDateString(),
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $fin->toDateString(),
            'fecha_emision' => $inicio->toDateString(),
            'fecha_vencimiento' => $vencimiento,
            'precio' => $precio,
            'dia_pago' => $diaPago,
            'tipo_duracion' => $tipo,
            'duracion' => $duracion,
            'estado_cuota' => 'PROGRAMADA',
            'mensaje' => 'La cuota se programa sin emitir venta anticipada. Ventas y Cartera permanecen intactas.',
        ];
    }

    public function registrar(int $id, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $usuarioId): array {
            $p = $this->previsualizar($id, true);
            $ahora = now();
            $periodoId = DB::table('membresias.membresia_periodos')->insertGetId([
                'membresia_id' => $id,
                'numero_periodo' => $p['numero_periodo_nuevo'],
                'fecha_inicio' => $p['fecha_inicio'],
                'fecha_fin' => $p['fecha_fin'],
                'precio' => $p['precio'],
                'estado' => 'PROGRAMADO',
                'renovacion_manual' => true,
                'venta_id' => null,
                'generado_at' => $ahora,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $cuotaId = DB::table('membresias.membresia_cuotas')->insertGetId([
                'membresia_id' => $id,
                'periodo_id' => $periodoId,
                'numero_cuota' => $p['numero_periodo_nuevo'],
                'monto' => $p['precio'],
                'fecha_programada_emision' => $p['fecha_emision'],
                'fecha_vencimiento' => $p['fecha_vencimiento'],
                'estado' => 'PROGRAMADA',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            DB::table('membresias.membresias')->where('id', $id)->update([
                'cantidad_periodos_contratados' => $p['numero_periodo_nuevo'],
                'fecha_fin' => $p['fecha_fin'],
                'updated_at' => $ahora,
            ]);
            // El snapshot de condiciones pactadas es INMUTABLE. La ampliación
            // vive en periodos/cuotas e historial comercial, no altera el original.
            app(HistorialComercialMembresiaServicio::class)->registrarContrato(
                $id, 'RENOVACION_MANUAL', $p + [
                    'periodo_id' => $periodoId, 'cuota_id' => $cuotaId,
                    'usuario_id' => $usuarioId, 'fecha_fin_anterior' => $p['vigencia_actual'],
                    'fecha_fin_nueva' => $p['fecha_fin'],
                ], 'Renovación manual del mismo contrato, sin venta anticipada.'
            );
            return $p + ['periodo_id' => $periodoId, 'cuota_id' => $cuotaId];
        });
    }
}
