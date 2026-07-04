<?php

namespace App\Queries\Personas;

use Illuminate\Support\Facades\DB;

class PlanRutinaQuery
{
    public function listarPlanes(array $filtros = []): array
    {
        $query = DB::table('entrenamiento.planes as p')
            ->join('core.personas as c', 'c.id', '=', 'p.persona_id')
            ->selectRaw("
                p.id,
                p.persona_id,
                p.nombre,
                p.objetivo,
                p.fecha_inicio,
                p.fecha_fin,
                p.estado,
                p.observaciones,
                c.numero_identificacion as cedula,
                c.nombres,
                c.apellidos
            ");

        if (!empty($filtros['buscar'])) {
            $buscar = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('p.nombre', 'like', $buscar)
                    ->orWhere('c.numero_identificacion', 'like', $buscar)
                    ->orWhere('c.nombres', 'like', $buscar)
                    ->orWhere('c.apellidos', 'like', $buscar);
            });
        }

        if (!empty($filtros['estado'])) {
            $query->where('p.estado', $filtros['estado']);
        }

        return $query->orderByDesc('p.fecha_inicio')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'persona_id' => (int) $item->persona_id,
                'nombre' => $item->nombre,
                'objetivo' => $item->objetivo,
                'fecha_inicio' => $item->fecha_inicio,
                'fecha_fin' => $item->fecha_fin,
                'estado' => $item->estado,
                'observaciones' => $item->observaciones,
                'cedula' => $item->cedula,
                'nombre_completo' => trim($item->nombres . ' ' . ($item->apellidos ?? '')),
            ])
            ->all();
    }

    public function listarRutinasPorPlan(int $planId): array
    {
        return DB::table('entrenamiento.rutinas as r')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'r.ejercicio_id')
            ->leftJoin('entrenamiento.ejercicios as et', 'et.id', '=', 'r.ejercicio_transferencia_id')
            ->where('r.plan_id', $planId)
            ->selectRaw("
                r.id,
                r.plan_id,
                r.semana,
                r.dia,
                r.bloque,
                r.ejercicio_id,
                r.series,
                r.repeticiones,
                r.carga_objetivo,
                r.tipo_carga,
                r.unidad_objetivo,
                r.tempo,
                r.rpe,
                r.descanso_segundos,
                r.bloque_orden,
                r.orden,
                r.notas,
                r.ejercicio_transferencia_id,
                r.repeticiones_transferencia,
                r.series_detalles,
                e.nombre as ejercicio_nombre,
                et.nombre as transferencia_nombre
            ")
            ->orderBy('r.semana')
            ->orderBy('r.dia')
            ->orderBy('r.bloque_orden')
            ->orderBy('r.orden')
            ->orderBy('r.id')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'plan_id' => (int) $item->plan_id,
                'semana' => (int) $item->semana,
                'dia' => $item->dia,
                'bloque' => $item->bloque,
                'ejercicio_id' => (int) $item->ejercicio_id,
                'ejercicio_nombre' => $item->ejercicio_nombre,
                'series' => (int) $item->series,
                'repeticiones' => $item->repeticiones,
                'carga_objetivo' => $item->carga_objetivo !== null ? (float) $item->carga_objetivo : null,
                'tipo_carga' => $item->tipo_carga,
                'unidad_objetivo' => $item->unidad_objetivo,
                'tempo' => $item->tempo,
                'rpe' => $item->rpe !== null ? (float) $item->rpe : null,
                'descanso_segundos' => $item->descanso_segundos !== null ? (int) $item->descanso_segundos : null,
                'bloque_orden' => $item->bloque_orden !== null ? (int) $item->bloque_orden : 1,
                'orden' => $item->orden !== null ? (int) $item->orden : 1,
                'notas' => $item->notas,
                'ejercicio_transferencia_id' => $item->ejercicio_transferencia_id !== null ? (int) $item->ejercicio_transferencia_id : null,
                'repeticiones_transferencia' => $item->repeticiones_transferencia !== null ? (int) $item->repeticiones_transferencia : null,
                'transferencia_nombre' => $item->transferencia_nombre,
                'series_detalles' => $item->series_detalles ? json_decode($item->series_detalles, true) : null,
            ])
            ->all();
    }

    public function listarPlantillas(): array
    {
        return DB::table('entrenamiento.rutina_plantillas as p')
            ->leftJoin('entrenamiento.rutina_plantilla_detalles as d', 'd.plantilla_id', '=', 'p.id')
            ->selectRaw("
                p.id,
                p.nombre,
                p.objetivo,
                p.descripcion,
                p.activa,
                COUNT(d.id) as total_items
            ")
            ->groupBy('p.id', 'p.nombre', 'p.objetivo', 'p.descripcion', 'p.activa')
            ->orderByDesc('p.id')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'nombre' => $item->nombre,
                'objetivo' => $item->objetivo,
                'descripcion' => $item->descripcion,
                'activa' => (bool) $item->activa,
                'total_items' => (int) $item->total_items,
            ])
            ->all();
    }
}
