<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\CobrosMetodoPagoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CobrosMetodoPagoExportacionServicio
{
    public function __construct(
        private readonly CobrosMetodoPagoServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->fecha ?? '',
            $i->sede ?? '',
            $i->metodo_pago ?? '',
            (int) ($i->operaciones ?? 0),
            (float) ($i->total ?? 0),
        ])->all();

        return $this->documento->descargar(
            'cobros-metodo-pago',
            'Cobros por método de pago',
            'Cobros confirmados consolidados por fecha, sede y método.',
            ['Fecha', 'Sede', 'Método', 'Operaciones', 'Total'],
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
        );
    }
}
