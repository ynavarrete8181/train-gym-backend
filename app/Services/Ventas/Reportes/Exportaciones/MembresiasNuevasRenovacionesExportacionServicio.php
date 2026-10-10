<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\MembresiasNuevasRenovacionesServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembresiasNuevasRenovacionesExportacionServicio
{
    public function __construct(
        private readonly MembresiasNuevasRenovacionesServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->fecha_movimiento ?? '',
            $i->sede ?? '',
            $i->tipo_movimiento ?? '',
            $i->codigo_contrato ?? '',
            $i->cliente ?? '',
            $i->identificacion ?? '',
            $i->plan ?? '',
            $i->modalidad ?? '',
            (int) ($i->numero_periodo ?? 0),
            $i->estado_periodo ?? '',
            (float) ($i->precio ?? 0),
            (float) ($i->total_cobrado ?? 0),
            (float) ($i->saldo_pendiente ?? 0),
        ])->all();

        return $this->documento->descargar(
            'membresias-nuevas-renovaciones',
            'Membresías nuevas y renovaciones',
            'Altas y renovaciones de membresías por período, sede, plan y estado.',
            ['Fecha', 'Sede', 'Movimiento', 'Contrato', 'Cliente', 'Identificación', 'Plan', 'Modalidad', 'Período', 'Estado', 'Precio', 'Cobrado', 'Saldo'],
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
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[10] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[11] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[12] ?? 0)),
            ],
        );
    }
}
