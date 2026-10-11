<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\CarteraVencidaServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarteraVencidaExportacionServicio
{
    public function __construct(
        private readonly CarteraVencidaServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);

        $filas = collect($items)->map(fn ($i) => [
            $i->sede ?? '',
            $i->venta_numero ?? '',
            $i->cliente ?? '',
            $i->identificacion ?? '',
            $i->fecha_vencimiento ?? '',
            (int) ($i->dias_vencidos ?? 0),
            (float) ($i->total_venta ?? 0),
            (float) ($i->total_pagado ?? 0),
            (float) ($i->saldo_pendiente ?? 0),
            $i->responsable ?? '',
            $i->prioridad ?? '',
        ])->all();

        return $this->documento->descargar(
            'cartera-vencida',
            'Cartera vencida',
            'Cuentas vencidas y saldos pendientes por sede.',
            ['Sede', 'N.º venta', 'Cliente', 'Identificación', 'Vencimiento', 'Días vencidos', 'Total', 'Pagado', 'Saldo', 'Responsable', 'Prioridad'],
            $filas,
            [
                'Sedes' => $this->recopila->sedesTexto($items),
                'Filtros aplicados' => $this->recopila->filtrosTexto($filtros),
            ],
            $usuarioId,
            [
                'TOTAL',
                '',
                '',
                '',
                '',
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[6] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[7] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[8] ?? 0)),
                '',
                '',
            ],
        );
    }
}
