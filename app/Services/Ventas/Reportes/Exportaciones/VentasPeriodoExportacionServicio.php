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
        $filas = collect($items)->map(fn ($i) => [
            $i->fecha ?? '',
            $i->sede ?? '',
            $i->venta_numero ?? '',
            $i->cliente ?? '',
            $i->identificacion ?? '',
            $i->tipo_venta ?? '',
            $i->estado ?? '',
            $i->responsable_comercial ?? '',
            (float) ($i->total_venta ?? 0),
            (float) ($i->total_cobrado ?? 0),
            (float) ($i->saldo_pendiente ?? 0),
        ])->all();

        return $this->documento->descargar(
            'ventas-periodo',
            'Ventas por período',
            'Detalle transaccional de ventas por rango de fechas.',
            ['Fecha', 'Sede', 'N.º venta', 'Cliente', 'Identificación', 'Tipo', 'Estado', 'Responsable', 'Total', 'Cobrado', 'Saldo'],
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
        );
    }
}
