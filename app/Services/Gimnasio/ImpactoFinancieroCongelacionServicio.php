<?php

namespace App\Services\Gimnasio;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3: conciliación de solo lectura.
 * El estado PAGADA de las cuotas se deriva de ventas.pagos CONFIRMADO;
 * no es una columna grabada en membresia_cuotas.
 */
final class ImpactoFinancieroCongelacionServicio
{
    /**
     * @return array<string, mixed>
     */
    public function previsualizar(int $membresiaId, string $inicio, string $fin, string $tipo): array
    {
        $dias = (int) CarbonImmutable::parse($inicio)->diffInDays(CarbonImmutable::parse($fin)) + 1;
        $cuotas = DB::table('membresias.membresia_cuotas as c')
            ->leftJoin('ventas.ventas as v', 'v.id', '=', 'c.venta_id')
            ->where('c.membresia_id', $membresiaId)
            ->orderBy('c.numero_cuota')
            ->get([
                'c.id', 'c.numero_cuota', 'c.estado', 'c.monto', 'c.venta_id',
                'c.fecha_programada_emision', 'c.fecha_vencimiento',
                'v.estado as venta_estado', 'v.total as venta_total',
            ]);
        $filas = [];
        $requiereRevision = false;
        $cuotasReprogramables = 0;
        $deudaEmitida = 0.0;
        $pagosConfirmados = 0.0;

        foreach ($cuotas as $cuota) {
            $pagado = $cuota->venta_id
                ? (float) DB::table('ventas.pagos')->where('venta_id', $cuota->venta_id)
                    ->where('estado', 'CONFIRMADO')->sum('monto')
                : 0.0;
            $pagosConfirmados += $pagado;
            $total = (float) ($cuota->venta_total ?? $cuota->monto);
            $saldo = $cuota->venta_id && $cuota->venta_estado !== 'ANULADA'
                ? max(0, round($total - $pagado, 2))
                : 0.0;
            $deudaEmitida += $saldo;
            $programadaSinVenta = $cuota->estado === 'PROGRAMADA' && ! $cuota->venta_id;
            $afectada = $programadaSinVenta
                && $cuota->fecha_programada_emision >= $inicio;
            $nuevaEmision = $cuota->fecha_programada_emision;
            $nuevoVencimiento = $cuota->fecha_vencimiento;
            $accion = 'SIN_CAMBIOS';

            if ($tipo === 'FACTURACION' && $afectada) {
                $nuevaEmision = CarbonImmutable::parse($cuota->fecha_programada_emision)
                    ->addDays($dias)->toDateString();
                $nuevoVencimiento = CarbonImmutable::parse($cuota->fecha_vencimiento)
                    ->addDays($dias)->toDateString();
                $accion = 'REPROGRAMACION_PROPUESTA';
                $cuotasReprogramables++;
            }

            if ($cuota->venta_id && $saldo > 0 && $cuota->venta_estado !== 'ANULADA') {
                // El saldo emitido no se cancela por congelar: se muestra como
                // obligación existente y debe conciliarse antes de aprobar ajustes.
                $requiereRevision = true;
                $accion = 'VENTA_EMITIDA_REVISAR_CARTERA';
            } elseif ($cuota->venta_id && $pagado > 0) {
                $accion = 'PAGO_CONFIRMADO_CONSERVAR';
            }

            $filas[] = [
                'cuota_id' => (int) $cuota->id,
                'numero' => (int) $cuota->numero_cuota,
                'estado' => $cuota->estado,
                'venta_id' => $cuota->venta_id ? (int) $cuota->venta_id : null,
                'monto' => round((float) $cuota->monto, 2),
                'pagado_confirmado' => round($pagado, 2),
                'saldo_emitido' => round($saldo, 2),
                'emision_anterior' => $cuota->fecha_programada_emision,
                'emision_propuesta' => $nuevaEmision,
                'vencimiento_anterior' => $cuota->fecha_vencimiento,
                'vencimiento_propuesto' => $nuevoVencimiento,
                'accion' => $accion,
            ];
        }

        return [
            'tipo' => $tipo,
            'dias' => $dias,
            'cuotas' => $filas,
            'cuotas_reprogramables' => $cuotasReprogramables,
            'pagos_confirmados' => round($pagosConfirmados, 2),
            'deuda_emitida' => round($deudaEmitida, 2),
            'requiere_revision_cartera' => $requiereRevision,
            'modifica_ventas' => false,
            'modifica_pagos' => false,
            'es_simulacion' => true,
        ];
    }
}
