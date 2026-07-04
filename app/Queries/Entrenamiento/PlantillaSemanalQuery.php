<?php

namespace App\Queries\Entrenamiento;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlantillaSemanalQuery
{
    public function listar(array $filtros = []): array
    {
        $diasSubquery = DB::table('entrenamiento.plantilla_semana_dias')
            ->selectRaw('plantilla_id, COUNT(*) AS total_dias')
            ->groupBy('plantilla_id');

        $query = DB::table('entrenamiento.plantillas_semanales as p')
            ->leftJoinSub($diasSubquery, 'd', fn ($join) => $join->on('d.plantilla_id', '=', 'p.id'))
            ->selectRaw("
                p.id,
                p.nombre,
                p.objetivo,
                p.disciplina,
                p.total_dias,
                p.activa,
                p.observaciones,
                COALESCE(d.total_dias, 0) as dias_configurados
            ");

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($nested) use ($buscar) {
                $nested->where('p.nombre', 'like', $buscar)
                    ->orWhere('p.objetivo', 'like', $buscar)
                    ->orWhere('p.disciplina', 'like', $buscar);
            });
        }

        if (array_key_exists('activa', $filtros) && $filtros['activa'] !== null && $filtros['activa'] !== '') {
            $query->where('p.activa', filter_var($filtros['activa'], FILTER_VALIDATE_BOOL));
        }

        return $query
            ->orderByDesc('p.id')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'nombre' => $item->nombre,
                'objetivo' => $item->objetivo,
                'disciplina' => $item->disciplina,
                'total_dias' => (int) $item->total_dias,
                'activa' => (bool) $item->activa,
                'observaciones' => $item->observaciones,
                'dias_configurados' => (int) $item->dias_configurados,
            ])
            ->all();
    }

    public function obtener(int $id): ?array
    {
        $plantilla = DB::table('entrenamiento.plantillas_semanales')->where('id', $id)->first();
        if (!$plantilla) {
            return null;
        }

        $dias = DB::table('entrenamiento.plantilla_semana_dias')
            ->where('plantilla_id', $id)
            ->orderBy('orden_dia')
            ->get();

        $diaIds = $dias->pluck('id')->all();
        $bloques = empty($diaIds) ? collect() : DB::table('entrenamiento.plantilla_semana_bloques')->whereIn('plantilla_dia_id', $diaIds)->orderBy('orden')->get();
        $bloqueIds = $bloques->pluck('id')->all();
        $ejercicios = empty($bloqueIds)
            ? collect()
            : DB::table('entrenamiento.plantilla_semana_ejercicios as pe')
                ->leftJoin('entrenamiento.ejercicios as e', 'e.id', '=', 'pe.ejercicio_id')
                ->whereIn('pe.plantilla_bloque_id', $bloqueIds)
                ->selectRaw('pe.*, e.nombre as ejercicio_nombre_catalogo')
                ->orderBy('pe.orden')
                ->get();
        $ejercicioIds = $ejercicios->pluck('id')->all();
        $series = empty($ejercicioIds) ? collect() : DB::table('entrenamiento.plantilla_semana_ejercicio_series')->whereIn('plantilla_ejercicio_id', $ejercicioIds)->orderBy('numero_serie')->get();
        $transferencias = empty($ejercicioIds)
            ? collect()
            : DB::table('entrenamiento.plantilla_semana_ejercicio_transferencias as pt')
                ->leftJoin('entrenamiento.ejercicios as e', 'e.id', '=', 'pt.ejercicio_id')
                ->whereIn('pt.plantilla_ejercicio_id', $ejercicioIds)
                ->selectRaw('pt.*, e.nombre as ejercicio_nombre_catalogo')
                ->orderBy('pt.orden')
                ->get();
        $transferenciaIds = $transferencias->pluck('id')->all();
        $transferSeries = empty($transferenciaIds) ? collect() : DB::table('entrenamiento.plantilla_semana_transferencia_series')->whereIn('transferencia_id', $transferenciaIds)->orderBy('numero_serie')->get();

        return [
            'plantilla' => [
                'id' => (int) $plantilla->id,
                'nombre' => $plantilla->nombre,
                'objetivo' => $plantilla->objetivo,
                'disciplina' => $plantilla->disciplina,
                'total_dias' => (int) $plantilla->total_dias,
                'activa' => (bool) $plantilla->activa,
                'observaciones' => $plantilla->observaciones,
            ],
            'dias' => $this->mapDias($dias, $bloques, $ejercicios, $series, $transferencias, $transferSeries),
        ];
    }

    private function mapDias(Collection $dias, Collection $bloques, Collection $ejercicios, Collection $series, Collection $transferencias, Collection $transferSeries): array
    {
        return $dias->map(function ($dia) use ($bloques, $ejercicios, $series, $transferencias, $transferSeries) {
            return [
                'id' => (int) $dia->id,
                'orden_dia' => (int) $dia->orden_dia,
                'dia' => $dia->dia,
                'nombre_sesion' => $dia->nombre_sesion,
                'observaciones' => $dia->observaciones,
                'bloques' => $bloques->where('plantilla_dia_id', $dia->id)->values()->map(function ($bloque) use ($ejercicios, $series, $transferencias, $transferSeries) {
                    return [
                        'id' => (int) $bloque->id,
                        'nombre' => $bloque->nombre,
                        'tipo_bloque' => $bloque->tipo_bloque,
                        'orden' => (int) $bloque->orden,
                        'observaciones' => $bloque->observaciones,
                        'ejercicios' => $ejercicios->where('plantilla_bloque_id', $bloque->id)->values()->map(function ($ejercicio) use ($series, $transferencias, $transferSeries) {
                            return [
                                'id' => (int) $ejercicio->id,
                                'ejercicio_id' => $ejercicio->ejercicio_id ? (int) $ejercicio->ejercicio_id : null,
                                'ejercicio_nombre' => $ejercicio->ejercicio_nombre_catalogo ?: $ejercicio->nombre_libre,
                                'nombre_libre' => $ejercicio->nombre_libre,
                                'orden' => (int) $ejercicio->orden,
                                'lado' => $ejercicio->lado,
                                'observaciones' => $ejercicio->observaciones,
                                'usa_rm' => (bool) $ejercicio->usa_rm,
                                'modo_prescripcion' => $ejercicio->modo_prescripcion,
                                'descanso_segundos' => $ejercicio->descanso_segundos ? (int) $ejercicio->descanso_segundos : null,
                                'tempo' => $ejercicio->tempo,
                                'rpe_objetivo' => $ejercicio->rpe_objetivo !== null ? (float) $ejercicio->rpe_objetivo : null,
                                'series' => $series->where('plantilla_ejercicio_id', $ejercicio->id)->values()->map(fn ($serie) => [
                                    'id' => (int) $serie->id,
                                    'numero_serie' => (int) $serie->numero_serie,
                                    'tipo_carga' => $serie->tipo_carga,
                                    'porcentaje_rm' => $serie->porcentaje_rm !== null ? (float) $serie->porcentaje_rm : null,
                                    'carga_fija' => $serie->carga_fija !== null ? (float) $serie->carga_fija : null,
                                    'unidad_carga' => $serie->unidad_carga,
                                    'repeticiones' => $serie->repeticiones,
                                    'tiempo_segundos' => $serie->tiempo_segundos ? (int) $serie->tiempo_segundos : null,
                                    'distancia_metros' => $serie->distancia_metros !== null ? (float) $serie->distancia_metros : null,
                                    'rpe' => $serie->rpe !== null ? (float) $serie->rpe : null,
                                    'descanso_segundos' => $serie->descanso_segundos ? (int) $serie->descanso_segundos : null,
                                    'tempo' => $serie->tempo,
                                    'observaciones' => $serie->observaciones,
                                ])->all(),
                                'transferencias' => $transferencias->where('plantilla_ejercicio_id', $ejercicio->id)->values()->map(function ($transferencia) use ($transferSeries) {
                                    return [
                                        'id' => (int) $transferencia->id,
                                        'ejercicio_id' => $transferencia->ejercicio_id ? (int) $transferencia->ejercicio_id : null,
                                        'ejercicio_nombre' => $transferencia->ejercicio_nombre_catalogo ?: $transferencia->nombre_libre,
                                        'nombre_libre' => $transferencia->nombre_libre,
                                        'orden' => (int) $transferencia->orden,
                                        'modo_aplicacion' => $transferencia->modo_aplicacion,
                                        'observaciones' => $transferencia->observaciones,
                                        'series' => $transferSeries->where('transferencia_id', $transferencia->id)->values()->map(fn ($serie) => [
                                            'id' => (int) $serie->id,
                                            'numero_serie' => (int) $serie->numero_serie,
                                            'tipo_carga' => $serie->tipo_carga,
                                            'porcentaje_rm' => $serie->porcentaje_rm !== null ? (float) $serie->porcentaje_rm : null,
                                            'carga_fija' => $serie->carga_fija !== null ? (float) $serie->carga_fija : null,
                                            'unidad_carga' => $serie->unidad_carga,
                                            'repeticiones' => $serie->repeticiones,
                                            'tiempo_segundos' => $serie->tiempo_segundos ? (int) $serie->tiempo_segundos : null,
                                            'distancia_metros' => $serie->distancia_metros !== null ? (float) $serie->distancia_metros : null,
                                            'rpe' => $serie->rpe !== null ? (float) $serie->rpe : null,
                                            'observaciones' => $serie->observaciones,
                                        ])->all(),
                                    ];
                                })->all(),
                            ];
                        })->all(),
                    ];
                })->all(),
            ];
        })->all();
    }
}
