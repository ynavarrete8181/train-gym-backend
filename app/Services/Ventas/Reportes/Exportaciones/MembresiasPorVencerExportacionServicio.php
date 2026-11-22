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
        $dia = fn ($f) => $f ? implode('/', array_reverse(explode('-', substr((string) $f, 0, 10)))) : '';
        $situacion = fn ($i) => match ($i->situacion ?? '') {
            'AUTOMATICA' => 'Se renueva sola el ' . $dia($i->fecha_programacion),
            'DEUDA' => 'No se renueva: debe $' . number_format((float) $i->deuda, 2),
            default => 'Renovar a mano',
        };
        $filas = collect($items)->map(fn ($i) => [
            $i->cliente ?? '',
            $i->paga ?? '',
            $i->telefono ?? '',
            trim(($i->codigo_contrato ?? '') . ' · ' . ($i->plan ?? ''), ' ·'),
            $dia($i->fecha_fin ?? null),
            (int) ($i->dias_restantes ?? 0),
            $situacion($i),
            (float) ($i->deuda ?? 0),
            (float) ($i->monto_renovacion ?? 0),
        ])->all();

        return $this->documento->descargar(
            'membresias-por-vencer',
            'Membresías por vencer',
            'Qué pasa con cada contrato al vencer, a quién llamar y cuánto pagará al renovar.',
            ['Cliente', 'Paga', 'Teléfono', 'Contrato', 'Vence', 'Días', 'Al vencer', 'Deuda', 'Pagará al renovar'],
            $filas,
            $this->recopila->metadataPeriodo($filtros, 'vence_desde', 'vence_hasta'),
            $usuarioId,
            ['TOTAL', '', '', '', '', '', '',
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[7] ?? 0)),
                fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[8] ?? 0))],
        );
    }
}
