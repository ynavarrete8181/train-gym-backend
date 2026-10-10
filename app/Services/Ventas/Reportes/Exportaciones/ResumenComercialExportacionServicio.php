<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\ResumenComercialServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResumenComercialExportacionServicio
{
    public function __construct(
        private readonly ResumenComercialServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $datos = $this->reporte->consultar($filtros, $usuarioId);
        $filas = collect($datos['por_sede'] ?? [])->map(fn ($fila) => [
            $fila['sede'] ?? '',
            (int) ($fila['transacciones'] ?? 0),
            (float) ($fila['total_ventas'] ?? 0),
        ])->all();

        $metadata = array_merge(
            $this->recopila->metadataPeriodo($filtros),
            [
                'Período comparativo' => isset($datos['comparativo'])
                    ? (($datos['comparativo']['desde'] ?? '') . ' - ' . ($datos['comparativo']['hasta'] ?? ''))
                    : null,
                'Total ventas' => $datos['indicadores']['total_ventas'] ?? 0,
                'Total cobrado' => $datos['indicadores']['total_cobrado'] ?? 0,
            ]
        );

        return $this->documento->descargar(
            'resumen-comercial',
            'Resumen comercial',
            'Indicadores consolidados de ventas y cobros por período y sede.',
            ['Sede', 'Transacciones', 'Ventas'],
            $filas,
            $metadata,
            $usuarioId,
        );
    }
}
