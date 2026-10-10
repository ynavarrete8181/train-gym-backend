<?php

namespace App\Services\Auditoria\Exportaciones;

use App\Services\Auditoria\TrazabilidadComercialServicio;
use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrazabilidadComercialExportacionServicio
{
    public function __construct(
        private readonly TrazabilidadComercialServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->reporte->todos($filtros);

        $filas = collect($items)->map(fn ($i) => [
            $i->created_at ?? '',
            $i->sede ?? '',
            $i->proceso ?? '',
            $i->referencia ?? '',
            $i->accion ?? '',
            $i->usuario_nombre ?? 'Sistema',
            $i->rol ?? '',
            $i->descripcion ?? '',
            $i->ip ?? '',
        ])->all();

        return $this->documento->descargar(
            'trazabilidad-comercial',
            'Trazabilidad comercial',
            'Historial de acciones sobre ventas, pagos, caja, cartera y membresías.',
            ['Fecha', 'Sede', 'Proceso', 'Referencia', 'Acción', 'Usuario', 'Rol', 'Descripción', 'IP'],
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
                fn (array $rows) => count($rows) . ' eventos',
                '',
            ],
        );
    }
}
