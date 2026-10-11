<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\VentasResponsableServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VentasResponsableExportacionServicio
{
    public function __construct(
        private readonly VentasResponsableServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->responsable ?? '',
            $i->sede ?? '',
            (int) ($i->ventas ?? 0),
            (int) ($i->clientes ?? 0),
            (float) ($i->total_ventas ?? 0),
            (float) ($i->total_cobrado ?? 0),
            (float) ($i->saldo_pendiente ?? 0),
            (float) ($i->ticket_promedio ?? 0),
            round((float) ($i->porcentaje_cobrado ?? 0), 2),
        ])->all();

        return $this->documento->descargar(
            'ventas-responsable',
            'Ventas por responsable',
            'Desempeño comercial por responsable y sede.',
            ['Responsable', 'Sede', 'Ventas', 'Clientes', 'Total vendido', 'Cobrado', 'Saldo', 'Ticket promedio', '% cobrado'],
            $filas,
            array_merge(
                $this->recopila->metadataPeriodo($filtros),
                ['Sedes' => $this->recopila->sedesTexto($items)]
            ),
            $usuarioId,
            [
                'TOTAL',
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (int) ($row[2] ?? 0)),
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[4] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[5] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[6] ?? 0)),
                '',
                '',
            ],
        );
    }
}
