<?php

namespace App\Services\Gimnasio;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Política C (según tipo de contrato).
 *
 * Fase 1: valida y calcula propuestas; NO cambia membresías, pagos,
 * cartera, cuotas, agendas ni estados. Ejecutar operaciones requiere
 * servicios transaccionales de las fases 2 a 4.
 */
final class PoliticaCicloVidaMembresia
{
    public const CONGELAR = 'CONGELAR';
    public const CANCELAR_PROGRAMACION = 'CANCELAR_PROGRAMACION';
    public const REACTIVAR = 'REACTIVAR';
    public const CERRAR = 'CERRAR';
    public const REAPERTURA_EXCEPCIONAL = 'REAPERTURA_EXCEPCIONAL';
    public const RECONTRATAR = 'RECONTRATAR';

    public const TIEMPO = 'TIEMPO';
    public const FACTURACION = 'FACTURACION';

    /**
     * Un intervalo cerrado [inicio, fin] incluye ambos extremos.
     * No se permiten solicitudes retroactivas salvo flujo excepcional.
     *
     * @return array{fecha_inicio:string, fecha_fin:string, dias:int}
     */
    public function validarIntervalo(string $inicio, string $fin, ?string $hoy = null): array
    {
        try {
            $desde = CarbonImmutable::createFromFormat('!Y-m-d', $inicio);
            $hasta = CarbonImmutable::createFromFormat('!Y-m-d', $fin);
            $fechaHoy = CarbonImmutable::createFromFormat('!Y-m-d', $hoy ?? now('America/Guayaquil')->toDateString());
        } catch (\Throwable) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Las fechas deben tener formato AAAA-MM-DD.']);
        }

        if (! $desde || ! $hasta || ! $fechaHoy
            || $desde->format('Y-m-d') !== $inicio || $hasta->format('Y-m-d') !== $fin) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Las fechas indicadas no son válidas.']);
        }
        if ($hasta->lessThan($desde)) {
            throw ValidationException::withMessages(['fecha_fin' => 'La fecha final no puede ser anterior al inicio.']);
        }
        if ($desde->lessThan($fechaHoy)) {
            throw ValidationException::withMessages(['fecha_inicio' => 'La congelación retroactiva requiere autorización excepcional.']);
        }

        return [
            'fecha_inicio' => $desde->toDateString(),
            'fecha_fin' => $hasta->toDateString(),
            'dias' => (int) $desde->diffInDays($hasta) + 1,
        ];
    }

    public function validarCongelacion(
        string $estado,
        string $tipo,
        bool $existeSuspensionNoTerminada,
        bool $hayCuotasProgramadas
    ): void {
        if (strtoupper($estado) !== 'ACTIVA') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede congelar una membresía activa. Otros casos requieren revisión administrativa.',
            ]);
        }
        if ($existeSuspensionNoTerminada) {
            throw ValidationException::withMessages([
                'membresia_id' => 'Ya existe una congelación pendiente o vigente. No se permiten períodos solapados.',
            ]);
        }
        if (! in_array(strtoupper($tipo), [self::TIEMPO, self::FACTURACION], true)) {
            throw ValidationException::withMessages(['tipo' => 'Selecciona congelación de tiempo o de facturación.']);
        }
        if (strtoupper($tipo) === self::FACTURACION && ! $hayCuotasProgramadas) {
            throw ValidationException::withMessages([
                'tipo' => 'La congelación de facturación requiere obligaciones programadas y una revisión financiera.',
            ]);
        }
    }

    public function validarReactivacion(string $estado, bool $haySuspensionVigente): void
    {
        if (strtoupper($estado) !== 'CONGELADA' || ! $haySuspensionVigente) {
            throw ValidationException::withMessages([
                'estado' => 'La reactivación solo procede con una congelación vigente.',
            ]);
        }
    }

    public function validarCierre(string $estado, string $motivo): void
    {
        if (in_array(strtoupper($estado), ['CANCELADA', 'VENCIDA'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'El contrato ya finalizó. Requiere recontratación o reapertura excepcional.',
            ]);
        }
        if (trim($motivo) === '') {
            throw ValidationException::withMessages([
                'motivo' => 'El cierre anticipado requiere un motivo.',
            ]);
        }
    }

    public function tipoSugerido(bool $contratoConCuotas, bool $pagadoCompletamente): string
    {
        return $contratoConCuotas && ! $pagadoCompletamente ? self::FACTURACION : self::TIEMPO;
    }

    /**
     * Calcula los días completos de una congelación al regresar antes o
     * en la fecha autorizada. El día del regreso vuelve a ser deportivo.
     *
     * @return array{fecha_regreso:string, fecha_fin_real:string, dias_reconocidos:int}
     */
    public function calcularRegreso(
        string $fechaInicio, string $fechaFinAutorizada, string $fechaRegreso
    ): array {
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', substr($fechaInicio, 0, 10));
        $finAutorizado = CarbonImmutable::createFromFormat('!Y-m-d', substr($fechaFinAutorizada, 0, 10));
        $regreso = CarbonImmutable::createFromFormat('!Y-m-d', substr($fechaRegreso, 0, 10));
        if (! $inicio || ! $finAutorizado || ! $regreso) {
            throw ValidationException::withMessages(['fecha' => 'El período de reactivación tiene fechas inválidas.']);
        }
        if ($regreso->lessThanOrEqualTo($inicio)) {
            throw ValidationException::withMessages([
                'fecha' => 'La reactivación exige al menos un día completo de suspensión.',
            ]);
        }
        $finReal = $regreso->subDay();
        if ($finReal->greaterThan($finAutorizado)) {
            throw ValidationException::withMessages([
                'fecha' => 'El regreso excede la fecha autorizada; requiere revisión administrativa.',
            ]);
        }
        return [
            'fecha_regreso' => $regreso->toDateString(),
            'fecha_fin_real' => $finReal->toDateString(),
            'dias_reconocidos' => (int) $inicio->diffInDays($finReal) + 1,
        ];
    }

    public function calcularFinAjustado(string $finOriginal, int $diasReconocidos): string
    {
        if ($diasReconocidos < 0) {
            throw ValidationException::withMessages(['dias' => 'Los días reconocidos no pueden ser negativos.']);
        }
        return CarbonImmutable::parse($finOriginal)->addDays($diasReconocidos)->toDateString();
    }
}
