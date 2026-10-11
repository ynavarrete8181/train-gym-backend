<?php

namespace App\Services\Metas\Exportaciones;

use App\Services\Metas\MetaComercialServicio;
use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MetasComercialesExportacionServicio
{
    public function __construct(
        private readonly MetaComercialServicio $metas,
        private readonly ReporteExcelDocumentoServicio $documento,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->metas->todos($filtros, $usuarioId);

        $filas = collect($items)->map(fn ($item) => [
            $item['sede'] ?? '',
            sprintf('%02d/%04d', (int) ($item['mes'] ?? 0), (int) ($item['anio'] ?? 0)),
            (float) ($item['meta_ventas'] ?? 0),
            (float) ($item['real_ventas'] ?? 0),
            (float) ($item['cumplimiento_ventas'] ?? 0) . '%',
            (float) ($item['meta_cobros'] ?? 0),
            (float) ($item['real_cobros'] ?? 0),
            (float) ($item['cumplimiento_cobros'] ?? 0) . '%',
            (int) ($item['meta_membresias_nuevas'] ?? 0),
            (int) ($item['real_membresias_nuevas'] ?? 0),
            (float) ($item['cumplimiento_membresias_nuevas'] ?? 0) . '%',
            (int) ($item['meta_renovaciones'] ?? 0),
            (int) ($item['real_renovaciones'] ?? 0),
            (float) ($item['cumplimiento_renovaciones'] ?? 0) . '%',
            $item['estado'] ?? '',
        ])->all();

        return $this->documento->descargar(
            'metas-comerciales',
            'Metas comerciales',
            'Seguimiento de objetivos comerciales por sede y período.',
            [
                'Sede',
                'Período',
                'Meta ventas',
                'Ventas reales',
                '% ventas',
                'Meta cobros',
                'Cobros reales',
                '% cobros',
                'Meta nuevas',
                'Nuevas reales',
                '% nuevas',
                'Meta renovaciones',
                'Renovaciones reales',
                '% renovaciones',
                'Estado',
            ],
            $filas,
            [],
            $usuarioId,
            [
                'TOTAL',
                fn (array $rows) => count($rows) . ' metas',
            ],
        );
    }
}
