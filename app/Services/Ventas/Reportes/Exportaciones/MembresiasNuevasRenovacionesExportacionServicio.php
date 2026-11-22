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
        $tipos = ['NUEVA' => 'Nueva', 'RECONTRATACION' => 'Recontratación', 'RENOVACION' => 'Renovación'];
        $dia = fn ($f) => $f ? implode('/', array_reverse(explode('-', substr((string) $f, 0, 10)))) : '';
        $filas = collect($items)->map(fn ($i) => [
            $dia($i->fecha_movimiento ?? null),
            $tipos[$i->tipo_movimiento] ?? ($i->tipo_movimiento ?? ''),
            $i->cliente ?? '',
            $i->tutor ?? '',
            $i->codigo_contrato ?? '',
            trim(($i->plan ?? '') . ' · ' . ($i->modalidad ?? ''), ' ·'),
            $dia($i->fecha_inicio ?? null) . ' al ' . $dia($i->fecha_fin ?? null),
            (float) ($i->precio ?? 0),
            (float) ($i->descuento ?? 0),
            (float) ($i->total ?? 0),
            (float) ($i->total_cobrado ?? 0),
            (float) ($i->saldo_pendiente ?? 0),
        ])->all();
        $suma = fn (int $col) => fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[$col] ?? 0));

        return $this->documento->descargar(
            'membresias-nuevas-renovaciones',
            'Membresías nuevas y renovaciones',
            'Clientes nuevos, recontrataciones y renovaciones del período.',
            ['Fecha', 'Tipo', 'Cliente', 'Tutor', 'Contrato', 'Plan', 'Período', 'Precio', 'Descuento', 'Total', 'Cobrado', 'Saldo'],
            $filas,
            array_merge(
                $this->recopila->metadataPeriodo($filtros),
                ['Sedes' => $this->recopila->sedesTexto($items)]
            ),
            $usuarioId,
            ['TOTAL', '', '', '', '', '', '', $suma(7), $suma(8), $suma(9), $suma(10), $suma(11)],
        );
    }
}
