<?php

namespace App\Queries\Personas;

use Illuminate\Support\Facades\DB;

class EvaluacionRmQuery
{
    public function listarEvaluaciones(array $filtros = []): array
    {
        $query = DB::table('entrenamiento.evaluaciones as e')
            ->join('core.personas as p', 'p.id', '=', 'e.persona_id')
            ->selectRaw("
                e.id,
                e.persona_id,
                e.tipo_evaluacion,
                e.fecha_evaluacion,
                e.resultado_resumen,
                e.nivel_resultado,
                e.fecha_proxima_evaluacion,
                e.observaciones,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos
            ");

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('p.numero_identificacion', 'like', $buscar)
                    ->orWhere('p.nombres', 'like', $buscar)
                    ->orWhere('p.apellidos', 'like', $buscar)
                    ->orWhere('e.tipo_evaluacion', 'like', $buscar);
            });
        }

        if (!empty($filtros['tipo_evaluacion'])) {
            $query->where('e.tipo_evaluacion', $filtros['tipo_evaluacion']);
        }

        return $query->orderByDesc('e.fecha_evaluacion')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'persona_id' => (int) $item->persona_id,
                'tipo_evaluacion' => $item->tipo_evaluacion,
                'fecha_evaluacion' => $item->fecha_evaluacion,
                'resultado_resumen' => $item->resultado_resumen,
                'nivel_resultado' => $item->nivel_resultado ?? 'MEDIO',
                'fecha_proxima_evaluacion' => $item->fecha_proxima_evaluacion,
                'observaciones' => $item->observaciones,
                'cedula' => $item->cedula,
                'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
            ])
            ->all();
    }

    public function listarRm(array $filtros = []): array
    {
        $query = DB::table('entrenamiento.rm_registros as r')
            ->join('core.personas as p', 'p.id', '=', 'r.persona_id')
            ->join('entrenamiento.ejercicios as ej', 'ej.id', '=', 'r.ejercicio_id')
            ->selectRaw("
                r.id,
                r.persona_id,
                r.ejercicio_id,
                r.tipo_registro,
                r.peso,
                r.repeticiones,
                r.rm_estimado,
                r.fecha_registro,
                r.fecha_proximo_control,
                r.observaciones,
                p.numero_identificacion as cedula,
                p.nombres,
                p.apellidos,
                ej.nombre as ejercicio_nombre
            ");

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('p.numero_identificacion', 'like', $buscar)
                    ->orWhere('p.nombres', 'like', $buscar)
                    ->orWhere('p.apellidos', 'like', $buscar)
                    ->orWhere('ej.nombre', 'like', $buscar);
            });
        }

        return $query->orderByDesc('r.fecha_registro')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'persona_id' => (int) $item->persona_id,
                'ejercicio_id' => (int) $item->ejercicio_id,
                'tipo_registro' => $item->tipo_registro,
                'peso' => (float) $item->peso,
                'repeticiones' => $item->repeticiones !== null ? (int) $item->repeticiones : null,
                'rm_estimado' => (float) $item->rm_estimado,
                'fecha_registro' => $item->fecha_registro,
                'fecha_proximo_control' => $item->fecha_proximo_control,
                'observaciones' => $item->observaciones,
                'cedula' => $item->cedula,
                'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
                'ejercicio_nombre' => $item->ejercicio_nombre,
            ])
            ->all();
    }
}
