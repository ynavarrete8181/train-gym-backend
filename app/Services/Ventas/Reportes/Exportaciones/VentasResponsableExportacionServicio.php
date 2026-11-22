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
            $i->usuario ?? '',
            $i->rol ?? '',
            (int) ($i->ventas ?? 0),
            (float) ($i->total_ventas ?? 0),
            (int) ($i->cobros ?? 0),
            (float) ($i->total_cobrado ?? 0),
            (float) ($i->efectivo ?? 0),
            (float) ($i->transferencia ?? 0),
            (float) ($i->otros ?? 0),
        ])->all();
        $suma = fn (int $col) => fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[$col] ?? 0));

        return $this->documento->descargar(
            'ventas-cobros-usuario',
            'Ventas y cobros por usuario',
            'Ventas registradas y dinero cobrado por cada usuario, en efectivo y transferencia.',
            ['Usuario', 'Rol', 'Ventas', 'Vendido', 'Cobros', 'Cobrado', 'Efectivo', 'Transferencia', 'Otros'],
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
            ['TOTAL', '', $suma(2), $suma(3), $suma(4), $suma(5), $suma(6), $suma(7), $suma(8)],
        );
    }
}
