<?php

namespace App\Services\Ventas\Reportes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait FiltraColumnasReporte
{
    /**
     * Aplica los filtros tipo Excel (columnas[columna][]=valor) sobre el resultado del reporte.
     * Las opciones de cada columna respetan los filtros de las demás columnas, como en Excel.
     *
     * @param  array<int, string>  $columnas  Únicas columnas filtrables: sus nombres se usan en la consulta.
     * @return array{0: Builder, 1: array<string, Collection>}
     */
    private function filtrarColumnas(Builder $query, array $filtros, array $columnas): array
    {
        $seleccion = collect($filtros['columnas'] ?? [])
            ->only($columnas)
            ->map(fn ($valores) => array_values(array_filter((array) $valores, fn ($valor) => $valor !== null && $valor !== '')))
            ->filter();

        $aplicar = function (Builder $consulta, Collection $elegidos): Builder {
            foreach ($elegidos as $columna => $valores) {
                $consulta->whereIn(DB::raw("\"{$columna}\"::text"), $valores);
            }

            return $consulta;
        };

        $base = DB::query()->fromSub($query, 'reporte');
        $opciones = [];

        // Al recopilar todas las páginas de una exportación no hacen falta las opciones.
        if (empty($filtros['sin_opciones'])) {
            foreach ($columnas as $columna) {
                $opciones[$columna] = $aplicar(clone $base, $seleccion->except($columna))
                    ->whereNotNull($columna)
                    ->selectRaw("\"{$columna}\"::text as valor")
                    ->groupBy($columna)
                    ->orderBy($columna)
                    ->limit(1000)
                    ->pluck('valor')
                    ->values();
            }
        }

        return [$aplicar($base, $seleccion), $opciones];
    }
}
