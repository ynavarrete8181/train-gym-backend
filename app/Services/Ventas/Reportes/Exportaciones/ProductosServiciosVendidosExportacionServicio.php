<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\ProductosServiciosVendidosServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductosServiciosVendidosExportacionServicio
{
    public function __construct(
        private readonly ProductosServiciosVendidosServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->sede ?? '',
            $i->tipo_item ?? '',
            $i->item ?? '',
            (int) ($i->ventas ?? 0),
            (float) ($i->cantidad ?? 0),
            (float) ($i->precio_promedio ?? 0),
            (float) ($i->total_vendido ?? 0),
        ])->all();

        return $this->documento->descargar(
            'productos-servicios-vendidos',
            'Productos y servicios vendidos',
            'Ítems vendidos por tipo, sede, cantidad e ingresos.',
            ['Sede', 'Tipo', 'Producto / servicio', 'Ventas', 'Cantidad', 'Precio promedio', 'Total vendido'],
            $filas,
            array_merge(
                $this->recopila->metadataPeriodo($filtros),
                ['Sedes' => $this->recopila->sedesTexto($items)]
            ),
            $usuarioId,
            [
                'TOTAL',
                '',
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (int) ($row[3] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[4] ?? 0)),
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[6] ?? 0)),
            ],
        );
    }
}
