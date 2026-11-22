<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\VentasPeriodoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VentasPeriodoExportacionServicio
{
    public function __construct(
        private readonly VentasPeriodoServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $estado = ['PAGADA' => 'Pagada', 'PARCIAL' => 'Parcial', 'PENDIENTE' => 'Pendiente'];
        $filas = collect($items)->map(fn ($i) => [
            $i->fecha ? implode('/', array_reverse(explode('-', (string) $i->fecha))) : '',
            $i->cliente ?? '',
            $i->tutor ?? '',
            $i->que_se_vendio ?? '',
            $estado[$i->estado] ?? ($i->estado ?? ''),
            $i->registro ?? '',
            (float) ($i->subtotal ?? 0),
            (float) ($i->descuento ?? 0),
            (float) ($i->total_venta ?? 0),
            (float) ($i->total_cobrado ?? 0),
            (float) ($i->saldo_pendiente ?? 0),
        ])->all();

        return $this->documento->descargar(
            'ventas-periodo',
            'Ventas por período',
            'Cada venta con qué se vendió, descuento, lo cobrado y lo que falta.',
            ['Fecha', 'Cliente', 'Tutor', 'Qué se vendió', 'Estado', 'Registró', 'Precio', 'Descuento', 'Total', 'Cobrado', 'Saldo'],
            $filas,
            array_merge(
                $this->recopila->metadataPeriodo($filtros),
                ['Sedes' => $this->recopila->sedesTexto($items)]
            ),
            $usuarioId,
            [
                'TOTAL', '', '', '', '', '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[6] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[7] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[8] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[9] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[10] ?? 0)),
            ],
        );
    }
}
