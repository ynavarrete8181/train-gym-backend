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
        $dia = fn ($f) => $f ? implode('/', array_reverse(explode('-', substr((string) $f, 0, 10)))) : '';
        $num = fn ($v) => $v === null ? null : (float) $v;
        $estado = fn ($i) => $i->pendiente_arqueo ? 'Pendiente de arqueo'
            : ($i->conciliado_at ? 'Conciliado por ' . ($i->conciliado_por_nombre ?? '') . ' el ' . $dia($i->conciliado_at) : 'Cerrado');
        $filas = collect($items)->map(fn ($i) => [
            $dia($i->fecha ?? null),
            ($i->hora_apertura ?? '') . ' – ' . ($i->hora_cierre ?? ''),
            $i->cajero ?? '',
            $num($i->fondo_anterior),
            (float) ($i->saldo_inicial ?? 0),
            $num($i->diferencia_fondo),
            (float) ($i->efectivo_cobrado ?? 0),
            (float) ($i->efectivo_esperado ?? 0),
            $num($i->efectivo_contado),
            $num($i->diferencia),
            (float) (($i->transferencia_cobrada ?? 0) + ($i->deposito_cobrado ?? 0)),
            $estado($i),
        ])->all();
        $suma = fn (int $col) => fn (array $rows) => collect($rows)->sum(fn ($row) => (float) ($row[$col] ?? 0));

        return $this->documento->descargar(
            'conciliacion-caja',
            'Conciliación de caja',
            'Cada turno con su fondo, efectivo esperado y contado, diferencias y estado del arqueo.',
            ['Fecha', 'Horario', 'Cajero', 'Cierre anterior', 'Fondo inicial', 'Fondo vs cierre anterior', 'Efectivo cobrado', 'Esperado', 'Contado', 'Diferencia', 'Transferencias', 'Estado'],
            $filas,
            $this->recopila->metadataPeriodo($filtros),
            $usuarioId,
            ['TOTAL', '', '', '', '', '', $suma(6), $suma(7), $suma(8), $suma(9), $suma(10), ''],
        );
    }
}
