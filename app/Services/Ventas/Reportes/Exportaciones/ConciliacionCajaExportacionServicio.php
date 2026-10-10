<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\ConciliacionCajaServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConciliacionCajaExportacionServicio
{
    public function __construct(
        private readonly ConciliacionCajaServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->fecha_cierre ?? '',
            $i->sede ?? '',
            $i->caja_nombre ?? '',
            $i->caja_codigo ?? '',
            $i->cajero ?? '',
            $i->fecha_apertura ?? '',
            $i->fecha_cierre ?? '',
            (float) ($i->saldo_inicial ?? 0),
            (float) ($i->efectivo_cobrado ?? 0),
            (float) ($i->efectivo_esperado ?? 0),
            (float) ($i->efectivo_contado ?? 0),
            (float) ($i->diferencia ?? 0),
            (float) ($i->transferencia_cobrada ?? 0),
            (float) ($i->tarjeta_cobrada ?? 0),
            (float) ($i->deposito_cobrado ?? 0),
            (float) ($i->otros_cobrado ?? 0),
            $i->tipo_cierre ?? '',
            (bool) ($i->requiere_arqueo ?? false) ? 'Pendiente' : 'Conciliado',
        ])->all();

        return $this->documento->descargar(
            'conciliacion-caja',
            'Conciliación de caja',
            'Cierres de caja, efectivo esperado, efectivo contado y diferencias.',
            ['Fecha', 'Sede', 'Caja', 'Código caja', 'Cajero', 'Apertura', 'Cierre', 'Saldo inicial', 'Efectivo cobrado', 'Esperado', 'Contado', 'Diferencia', 'Transferencias', 'Tarjetas', 'Depósitos', 'Otros', 'Tipo cierre', 'Conciliación'],
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
        );
    }
}
