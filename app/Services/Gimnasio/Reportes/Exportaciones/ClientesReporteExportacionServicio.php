<?php

namespace App\Services\Gimnasio\Reportes\Exportaciones;

use App\Services\Gimnasio\Reportes\ClientesReporteServicio;
use App\Services\Reportes\Documentos\RecopilaReportePaginadoServicio;
use App\Services\Reportes\Documentos\ReporteExcelDocumentoServicio;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientesReporteExportacionServicio
{
    private const ESTADOS = ['ACTIVO' => 'Activo', 'PROSPECTO' => 'Prospecto', 'SUSPENDIDO' => 'Suspendido', 'INACTIVO' => 'Inactivo'];
    private const MEMBRESIA = ['ACTIVA' => 'Activa', 'PENDIENTE_PAGO' => 'Pendiente de pago', 'CONGELADA' => 'Congelada', 'VENCIDA' => 'Vencida', 'CANCELADA' => 'Cancelada'];

    public function __construct(
        private readonly ClientesReporteServicio $reporte,
        private readonly ReporteExcelDocumentoServicio $documento,
        private readonly RecopilaReportePaginadoServicio $recopila,
    ) {}

    public function excel(array $filtros, int $usuarioId): StreamedResponse
    {
        $items = $this->recopila->todos(fn ($params, $uid) => $this->reporte->consultar($params, $uid), $filtros, $usuarioId);
        $fecha = fn ($f) => $f ? implode('/', array_reverse(explode('-', substr((string) $f, 0, 10)))) : '';
        $filas = collect($items)->map(fn ($i) => [
            $i->cliente, $i->codigo ?? '', $i->cedula, $i->edad ?? '', $i->telefono, $i->correo ?? '', $i->tutor, $i->sede,
            $i->membresia ?? 'Sin membresía', self::MEMBRESIA[$i->membresia_estado] ?? '', $fecha($i->vence),
            (float) $i->deuda, $fecha($i->ultima_asistencia), self::ESTADOS[$i->estado] ?? $i->estado, $fecha($i->registrado),
        ])->all();

        return $this->documento->descargar(
            'clientes',
            'Clientes',
            'Padrón de clientes con sus datos, membresía vigente, deuda, última asistencia y estado.',
            ['Cliente', 'Código', 'Cédula', 'Edad', 'Teléfono', 'Correo', 'Tutor', 'Sede', 'Membresía', 'Estado membresía', 'Vence', 'Deuda', 'Última asistencia', 'Estado', 'Registrado'],
            $filas,
            ['Clientes' => (string) count($filas), 'Sedes' => $this->recopila->sedesTexto($items)],
            $usuarioId,
            ['TOTAL', '', '', '', '', '', '', '', '', '', '', fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[11] ?? 0)), '', '', ''],
        );
    }
}
