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
        return [
            'Período desde' => $filtros[$desde] ?? null,
            'Período hasta' => $filtros[$hasta] ?? null,
            'Filtros aplicados' => $this->filtrosTexto($filtros),
        ];
    }

    public function filtrosTexto(array $filtros): string
    {
        $ignorar = ['page', 'per_page', 'busqueda', 'desde', 'hasta', 'vence_desde', 'vence_hasta'];

        return collect($filtros)
            ->reject(fn ($valor, $clave) => in_array($clave, $ignorar, true) || $valor === null || $valor === '' || $valor === [])
            ->map(function ($valor, $clave): string {
                $valorTexto = is_array($valor) ? implode(', ', $valor) : (string) $valor;
                return str_replace('_', ' ', ucfirst($clave)) . ': ' . $valorTexto;
            })
            ->implode(' | ');
    }
}
