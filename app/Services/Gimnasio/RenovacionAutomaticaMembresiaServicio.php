<?php

namespace App\Services\Gimnasio;

use App\Services\Concerns\RegistraAuditoria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renueva un período más los contratos con cuotas que tienen activada la renovación
 * automática. Usa la misma renovación del contrato que la manual: el período queda
 * programado, su venta se emite al iniciar y el acceso depende de su pago. Si una cuota
 * anterior sigue sin pagar, no renueva; así nunca se acumula más de una renovación sin pago.
 */
class RenovacionAutomaticaMembresiaServicio
{
    use RegistraAuditoria;

    /** Días antes del fin del contrato en que se programa el período siguiente. */
    public const DIAS_ANTICIPACION = 7;

    public function __construct(private readonly RenovacionContratoProgramadoServicio $renovacion)
    {
    }

    public function procesar(?string $fecha = null): array
    {
        $hoy = CarbonImmutable::parse($fecha ?: now()->toDateString())->startOfDay();
        $limite = $hoy->addDays(self::DIAS_ANTICIPACION)->toDateString();

        $ids = DB::table('membresias.membresias')
            ->where('renovacion_automatica', true)
            ->whereNotNull('cantidad_periodos_contratados')
            ->where('estado', 'ACTIVA')
            ->whereDate('fecha_fin', '>=', $hoy->toDateString())
            ->whereDate('fecha_fin', '<=', $limite)
            ->orderBy('id')
            ->pluck('id');

        $renovadas = [];
        $omitidas = [];
        foreach ($ids as $id) {
            try {
                $resultado = $this->renovacion->registrar((int) $id, null, 'RENOVACION_AUTOMATICA',
                    'Renovación automática del mismo contrato, sin venta anticipada.');
                $this->auditar('membresias', 'RENOVAR_AUTOMATICAMENTE', 'membresias.membresias', (int) $id, null, $resultado);
                $renovadas[] = (int) $id;
            } catch (ValidationException $e) {
                // Cuota sin pagar, congelación abierta u otra condición del contrato: se reintenta mañana.
                $omitidas[(int) $id] = collect($e->errors())->flatten()->first();
            }
        }

        return [
            'fecha' => $hoy->toDateString(),
            'candidatas' => $ids->count(),
            'renovadas' => $renovadas,
            'omitidas' => $omitidas,
        ];
    }
}
