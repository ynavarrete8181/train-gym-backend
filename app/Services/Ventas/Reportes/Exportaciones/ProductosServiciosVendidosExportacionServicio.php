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
        $tipos = ['MEMBRESIA' => 'Membresía', 'PRODUCTO' => 'Producto', 'SERVICIO' => 'Servicio'];
        $filas = collect($items)->map(fn ($i) => [
            $i->item ?? '',
            $tipos[$i->tipo_item] ?? ($i->tipo_item ?? ''),
            (int) ($i->ventas ?? 0),
            (int) ($i->clientes ?? 0),
            (float) ($i->precio_promedio ?? 0),
            (float) ($i->descuentos ?? 0),
            (float) ($i->total_vendido ?? 0),
            ((float) ($i->porcentaje ?? 0)) . ' %',
        ])->all();
        $suma = fn (int $col) => fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[$col] ?? 0));

        return $this->documento->descargar(
            'productos-servicios-vendidos',
            'Productos y servicios vendidos',
            'Qué se vende más: planes y productos con ventas, clientes, descuentos y total neto.',
            ['Plan o producto', 'Tipo', 'Ventas', 'Clientes', 'Precio', 'Descuentos', 'Total neto', '% del total'],
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
            ['TOTAL', '', $suma(2), '', '', $suma(5), $suma(6), '100 %'],
        );
    }
}
