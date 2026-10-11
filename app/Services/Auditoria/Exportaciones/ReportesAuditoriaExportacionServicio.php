<?php

namespace App\Services\Auditoria\Exportaciones;

use App\Services\Auditoria\ReportesAuditoriaServicio;
use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportesAuditoriaExportacionServicio
{
    public function __construct(
        private readonly ReportesAuditoriaServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $tipo = mb_strtoupper((string) ($filtros['tipo'] ?? 'USUARIO'));
        $items = $this->reporte->todos($filtros);

        [$titulo, $descripcion, $columnas, $filas] = $this->configurar($tipo, $items);

        return $this->documento->descargar(
            'auditoria-' . mb_strtolower(str_replace('_', '-', $tipo)),
            $titulo,
            $descripcion,
            $columnas,
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
            [
                'TOTAL',
                fn (array $rows) => count($rows) . ' registros',
            ],
        );
    }

    private function configurar(string $tipo, array $items): array
    {
        return match ($tipo) {
            'MODULO' => [
                'Auditoría - Actividad por módulo',
                'Eventos agrupados por módulo del sistema.',
                ['Módulo', 'Eventos', 'Usuarios', 'Acciones', 'Última actividad'],
                collect($items)->map(fn ($i) => [
                    $i->modulo ?? '',
                    (int) ($i->eventos ?? 0),
                    (int) ($i->usuarios ?? 0),
                    (int) ($i->acciones ?? 0),
                    $i->ultima_actividad ?? '',
                ])->all(),
            ],
            'CRITICOS' => [
                'Auditoría - Cambios críticos',
                'Operaciones sensibles registradas en la auditoría funcional.',
                ['Fecha', 'Usuario', 'Rol', 'Módulo', 'Tabla', 'Registro', 'Acción', 'Descripción', 'IP'],
                collect($items)->map(fn ($i) => [
                    $i->created_at ?? '',
                    $i->usuario ?? 'Sistema',
                    $i->rol ?? '',
                    $i->modulo ?? '',
                    $i->tabla ?? '',
                    $i->registro_id ?? '',
                    $i->accion ?? '',
                    $i->descripcion ?? '',
                    $i->ip ?? '',
                ])->all(),
            ],
            'ACCESOS_FALLIDOS' => [
                'Auditoría - Accesos fallidos',
                'Intentos de acceso fallidos agrupados por usuario e IP.',
                ['Usuario', 'IP', 'Intentos', 'Último intento', 'Último motivo'],
                collect($items)->map(fn ($i) => [
                    $i->usuario ?? '',
                    $i->ip ?? '',
                    (int) ($i->intentos ?? 0),
                    $i->ultimo_intento ?? '',
                    $i->ultimo_motivo ?? '',
                ])->all(),
            ],
            'ERRORES_RECURRENTES' => [
                'Auditoría - Errores recurrentes',
                'Errores técnicos agrupados para identificar recurrencias.',
                ['Módulo', 'Acción', 'Mensaje', 'Ocurrencias', 'Última ocurrencia'],
                collect($items)->map(fn ($i) => [
                    $i->modulo ?? '',
                    $i->accion ?? '',
                    $i->mensaje ?? '',
                    (int) ($i->ocurrencias ?? 0),
                    $i->ultima_ocurrencia ?? '',
                ])->all(),
            ],
            default => [
                'Auditoría - Actividad por usuario',
                'Actividad funcional agrupada por usuario y rol.',
                ['Usuario', 'Rol', 'Eventos', 'Módulos', 'Última actividad'],
                collect($items)->map(fn ($i) => [
                    $i->usuario ?? 'Sistema',
                    $i->rol ?? '',
                    (int) ($i->eventos ?? 0),
                    (int) ($i->modulos ?? 0),
                    $i->ultima_actividad ?? '',
                ])->all(),
            ],
        };
    }
}
