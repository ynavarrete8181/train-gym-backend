<?php

namespace App\Services\Alertas\Exportaciones;

use App\Services\Alertas\AlertaOperativaServicio;
use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlertasOperativasExportacionServicio
{
    public function __construct(
        private readonly AlertaOperativaServicio $alertas,
        private readonly ReporteExcelDocumentoServicio $documento,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->alertas->todos($filtros, $usuarioId);

        $filas = collect($items)->map(fn ($i) => [
            $i->sede ?? '',
            $i->tipo ?? '',
            $i->nivel ?? '',
            $i->titulo ?? '',
            $i->mensaje ?? '',
            $i->estado ?? '',
            $i->detectada_at ?? '',
            $i->ultima_deteccion_at ?? '',
            $i->resuelta_at ?? '',
        ])->all();

        return $this->documento->descargar(
            'alertas-operativas',
            'Alertas operativas',
            'Alertas detectadas automáticamente por Revive y su estado de resolución.',
            ['Sede', 'Tipo', 'Nivel', 'Título', 'Detalle', 'Estado', 'Detectada', 'Última detección', 'Resuelta'],
            $filas,
            [],
            $usuarioId,
            [
                'TOTAL',
                fn (array $rows) => count($rows) . ' alertas',
            ],
        );
    }
}
