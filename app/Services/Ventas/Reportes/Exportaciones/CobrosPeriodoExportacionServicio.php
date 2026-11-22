<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Ventas\Reportes\CobrosPeriodoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CobrosPeriodoExportacionServicio
{
    public function __construct(
        private readonly CobrosPeriodoServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->fecha ? implode('/', array_reverse(explode('-', $i->fecha))) : '',
            $i->hora ?? '',
            $i->codigo_cobro ?? '',
            $i->pagado_por ?? '',
            $i->cuentas ?? '',
            strtr((string) ($i->metodo_pago ?? ''), ['EFECTIVO' => 'Efectivo', 'TRANSFERENCIA' => 'Transferencia', 'TARJETA' => 'Tarjeta', 'DEPOSITO' => 'Depósito']),
            $i->referencia ?? '',
            $i->banco ?? '',
            $i->cajero ?? '',
            $i->sede ?? '',
            (float) ($i->total ?? 0),
        ])->all();

        return $this->documento->descargar(
            'cobros-periodo',
            'Cobros del período',
            'Cada cobro con quién pagó, las cuentas que pagó, método, comprobante, banco y cajero.',
            ['Fecha', 'Hora', 'Cobro', 'Pagado por', 'Cuentas pagadas', 'Método', 'N.º comprobante', 'Banco', 'Cajero', 'Sede', 'Total'],
            $filas,
            array_merge(
                $this->recopila->metadataPeriodo($filtros),
                ['Sedes' => $this->recopila->sedesTexto($items)]
            ),
            $usuarioId,
            ['TOTAL', '', '', '', '', '', '', '', '', '', fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[10] ?? 0))],
        );
    }
}
