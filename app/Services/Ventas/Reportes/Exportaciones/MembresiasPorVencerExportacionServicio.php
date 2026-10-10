<?php

namespace App\Services\Ventas\Reportes\Exportaciones;

use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Ventas\Reportes\MembresiasPorVencerServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembresiasPorVencerExportacionServicio
{
    public function __construct(
        private readonly MembresiasPorVencerServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $filas = collect($items)->map(fn ($i) => [
            $i->sede ?? '',
            $i->codigo_contrato ?? '',
            $i->cliente ?? '',
            $i->identificacion ?? '',
            $i->plan ?? '',
            $i->modalidad ?? '',
            (int) ($i->numero_periodo ?? 0),
            $i->fecha_fin ?? '',
            (int) ($i->dias_restantes ?? 0),
            (bool) ($i->renovable ?? false) ? 'Sí' : 'No',
            $i->estado_periodo ?? '',
            (float) ($i->saldo_pendiente ?? 0),
        ])->all();

        return $this->documento->descargar(
            'membresias-por-vencer',
            'Membresías por vencer',
            'Períodos vigentes próximos a finalizar para gestión de renovación.',
            ['Sede', 'Contrato', 'Cliente', 'Identificación', 'Plan', 'Modalidad', 'Período', 'Vence', 'Días restantes', 'Renovable', 'Estado', 'Saldo'],
            $filas,
            array_merge(
                $this->recopila->metadataPeriodo($filtros, 'vence_desde', 'vence_hasta'),
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
                '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[11] ?? 0)),
            ],
        );
    }
}
