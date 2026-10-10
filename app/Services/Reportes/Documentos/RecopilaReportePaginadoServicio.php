<?php

namespace App\Services\Reportes\Documentos;

class RecopilaReportePaginadoServicio
{
    public function todos(callable $consultar, array $filtros, int $usuarioId, int $porPagina = 50): array
    {
        $pagina = 1;
        $resultado = [];

        do {
            $consulta = $consultar(array_merge($filtros, [
                'page' => $pagina,
                'per_page' => $porPagina,
            ]), $usuarioId);

            $resultado = array_merge($resultado, $consulta['datos'] ?? []);
            $ultimaPagina = (int) ($consulta['meta']['ultima_pagina'] ?? 1);
            $pagina++;
        } while ($pagina <= $ultimaPagina);

        return $resultado;
    }

    public function metadataPeriodo(array $filtros, string $desde = 'desde', string $hasta = 'hasta'): array
    {
        $inicio = $filtros[$desde] ?? null;
        $fin = $filtros[$hasta] ?? null;

        $periodo = null;
        if ($inicio && $fin) {
            $periodo = $this->fechaDocumento($inicio) . ' - ' . $this->fechaDocumento($fin);
        } elseif ($inicio || $fin) {
            $periodo = $this->fechaDocumento($inicio ?: $fin);
        }

        return [
            'Período' => $periodo,
            'Filtros aplicados' => $this->filtrosTexto($filtros),
        ];
    }

    public function sedesTexto(array $items): string
    {
        $sedes = collect($items)
            ->map(fn ($item) => is_array($item) ? ($item['sede'] ?? null) : ($item->sede ?? null))
            ->filter(fn ($sede) => is_string($sede) && trim($sede) !== '')
            ->map(fn ($sede) => trim($sede))
            ->unique()
            ->values();

        if ($sedes->isEmpty()) {
            return 'Todas las sedes';
        }

        if ($sedes->count() <= 3) {
            return $sedes->implode(', ');
        }

        return $sedes->take(3)->implode(', ') . ' y ' . ($sedes->count() - 3) . ' más';
    }

    private function fechaDocumento(string $fecha): string
    {
        try {
            return \Carbon\Carbon::parse($fecha)->format('d/m/Y');
        } catch (\Throwable) {
            return $fecha;
        }
    }

    public function filtrosTexto(array $filtros): string
    {
        $ignorar = ['page', 'per_page', 'busqueda', 'desde', 'hasta', 'vence_desde', 'vence_hasta', 'sede_id'];

        return collect($filtros)
            ->reject(fn ($valor, $clave) => in_array($clave, $ignorar, true) || $valor === null || $valor === '' || $valor === [])
            ->map(function ($valor, $clave): string {
                $valorTexto = is_array($valor) ? implode(', ', $valor) : (string) $valor;
                return str_replace('_', ' ', ucfirst($clave)) . ': ' . $valorTexto;
            })
            ->implode(' | ');
    }
}
