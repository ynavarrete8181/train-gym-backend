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
            (float) ($fila['descuentos'] ?? 0),
            (float) ($fila['total_cobrado'] ?? 0),
            (float) ($fila['por_cobrar'] ?? 0),
        ])->all();
        $ind = $datos['indicadores'] ?? [];
        $variacion = fn ($v) => $v === null ? 'Sin datos suficientes del período anterior' : number_format((float) $v, 2) . ' %';

        $metadata = array_merge(
            $this->recopila->metadataPeriodo($filtros),
            [
                'Sedes' => $this->recopila->sedesTexto(collect($datos['por_sede'] ?? [])->all()),
                'Período comparativo' => isset($datos['comparativo'])
                    ? (($datos['comparativo']['desde'] ?? '') . ' - ' . ($datos['comparativo']['hasta'] ?? ''))
                    : null,
                'Ventas (facturado)' => '$' . number_format((float) ($ind['total_ventas'] ?? 0), 2),
                'Cobrado' => '$' . number_format((float) ($ind['total_cobrado'] ?? 0), 2),
                'Por cobrar' => '$' . number_format((float) ($ind['por_cobrar'] ?? 0), 2),
                'Descuentos otorgados' => '$' . number_format((float) ($ind['descuentos'] ?? 0), 2),
                'Membresías' => '$' . number_format((float) ($ind['membresias'] ?? 0), 2),
                'Productos y servicios' => '$' . number_format((float) ($ind['productos_servicios'] ?? 0), 2),
                'Variación de ventas' => $variacion($ind['variacion_ventas'] ?? null),
                'Variación de cobros' => $variacion($ind['variacion_cobros'] ?? null),
            ]
        );

        return $this->documento->descargar(
            'resumen-comercial',
            'Resumen comercial',
            'Indicadores consolidados de ventas y cobros por período y sede.',
            ['Sede', 'Transacciones', 'Ventas', 'Descuentos', 'Cobrado', 'Por cobrar'],
            $filas,
            $metadata,
            $usuarioId,
            ['TOTAL',
                fn (array $rows) => collect($rows)->sum(fn ($r) => (int) ($r[1] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($r) => (float) ($r[2] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($r) => (float) ($r[3] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($r) => (float) ($r[4] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($r) => (float) ($r[5] ?? 0)),
            ],
        );
    }
}
