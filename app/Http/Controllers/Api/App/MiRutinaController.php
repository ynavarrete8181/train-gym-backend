<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MiRutinaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'semana' => 'nullable|integer|min:1|max:52',
            'dia' => 'nullable|string|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
        ]);

        $deportista = DB::table('clientes.deportistas')
            ->where('usuario_id', $request->user()->id)
            ->first(['id']);

        if (! $deportista) {
            return $this->vacio('SIN_DEPORTISTA', 'La cuenta no tiene un deportista vinculado.');
        }

        $membresia = DB::table('membresias.membresias as m')
            ->join('membresias.planes as p', 'p.id', '=', 'm.plan_id')
            ->where('m.deportista_id', $deportista->id)
            ->where('m.estado', 'ACTIVA')
            ->whereDate('m.fecha_inicio', '<=', now('America/Guayaquil')->toDateString())
            ->whereDate('m.fecha_fin', '>=', now('America/Guayaquil')->toDateString())
            ->orderByRaw("CASE WHEN p.tipo_producto = 'MEMBRESIA' THEN 0 ELSE 1 END")
            ->orderByDesc('m.fecha_inicio')
            ->first(['m.id', 'm.plan_id', 'm.fecha_inicio', 'm.fecha_fin', 'p.nombre as membresia_nombre']);

        if (! $membresia) {
            return $this->vacio('SIN_MEMBRESIA_VIGENTE', 'Necesitas una membresía activa y vigente para acceder a tu rutina.');
        }

        // El modelo de entrenamiento actual relaciona el plan con el deportista,
        // no directamente con la membresía. Se aplica la condición comercial
        // y se consulta SOLO el plan de entrenamiento activo de este deportista.
        $plan = DB::table('entrenamiento.planes as pl')
            ->where('pl.cliente_id', $deportista->id)
            ->where('pl.estado', 'ACTIVO')
            ->whereDate('pl.fecha_inicio', '<=', now('America/Guayaquil')->toDateString())
            ->where(function ($q): void {
                $q->whereNull('pl.fecha_fin')->orWhereDate('pl.fecha_fin', '>=', now('America/Guayaquil')->toDateString());
            })
            ->orderByDesc('pl.fecha_inicio')
            ->orderByDesc('pl.id')
            ->first(['pl.id', 'pl.nombre', 'pl.objetivo', 'pl.fecha_inicio', 'pl.fecha_fin']);

        if (! $plan) {
            return $this->vacio('SIN_PLAN_ENTRENAMIENTO', 'Todavía no tienes un plan de entrenamiento activo configurado.');
        }

        $semana = (int) ($datos['semana'] ?? 1);
        $dia = $datos['dia'] ?? mb_strtoupper(now()->locale('es')->isoFormat('dddd'));
        $dia = strtr($dia, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);

        $ultimosRm = DB::table('entrenamiento.rm_registros as rm')
            ->where('rm.cliente_id', $deportista->id)
            ->whereIn('rm.id', function ($query) use ($deportista): void {
                $query->from('entrenamiento.rm_registros')
                    ->selectRaw('MAX(id)')
                    ->where('cliente_id', $deportista->id)
                    ->groupBy('ejercicio_id');
            })
            ->get(['rm.ejercicio_id', 'rm.rm_estimado', 'rm.fecha_registro'])
            ->keyBy('ejercicio_id');

        $ejercicios = DB::table('entrenamiento.rutinas as r')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'r.ejercicio_id')
            ->where('r.plan_id', $plan->id)
            ->where('r.semana', $semana)
            ->whereRaw('UPPER(r.dia) = ?', [$dia])
            ->where('e.activo', true)
            ->orderBy('r.orden')
            ->get([
                'r.id', 'r.semana', 'r.dia', 'r.bloque', 'r.orden', 'r.series',
                'r.repeticiones', 'r.carga_objetivo', 'r.tipo_carga',
                'r.descanso_segundos', 'r.notas',
                'e.id as ejercicio_id', 'e.nombre', 'e.grupo_muscular',
                'e.equipamiento', 'e.instrucciones', 'e.url_recurso', 'e.video_path',
            ])->map(function ($r) use ($ultimosRm): array {
                $rm = $ultimosRm->get($r->ejercicio_id);
                $porcentaje = $r->tipo_carga === 'PORCENTAJE_RM' ? (float) $r->carga_objetivo : null;
                $seriesPlanificadas = DB::table('entrenamiento.rutina_series')
                    ->where('rutina_id', $r->id)->orderBy('numero_serie')->get()
                    ->map(function ($serie) use ($rm): array {
                        $porcentajeSerie = $serie->porcentaje_rm !== null ? (float) $serie->porcentaje_rm : null;
                        $kg = $serie->tipo_carga === 'PORCENTAJE_RM' && $rm && $porcentajeSerie !== null
                            ? round(((float) $rm->rm_estimado * $porcentajeSerie) / 100, 2)
                            : ($serie->carga_fija !== null ? (float) $serie->carga_fija : null);

                        return [
                            'numero_serie' => (int) $serie->numero_serie,
                            'tipo_carga' => $serie->tipo_carga,
                            'porcentaje_rm' => $porcentajeSerie,
                            'reps' => $serie->repeticiones,
                            'descanso_segundos' => $serie->descanso_segundos,
                            'rpe' => $serie->rpe !== null ? (float) $serie->rpe : null,
                            'carga_kg' => $kg,
                            'carga_lb' => $kg !== null ? round($kg * 2.2046226218, 2) : null,
                            'prescripcion_carga' => [
                                'carga_redondeada' => $kg,
                                'carga_libras' => $kg !== null ? round($kg * 2.2046226218, 2) : null,
                                'rm_usado' => $rm ? (float) $rm->rm_estimado : null,
                                'rm_origen' => $rm ? 'ultimo_rm_usuario' : null,
                            ],
                        ];
                    })->values();
                $transferencias = DB::table('entrenamiento.rutina_transferencias as t')
                    ->join('entrenamiento.ejercicios as e', 'e.id', '=', 't.ejercicio_id')
                    ->where('t.rutina_id', $r->id)->orderBy('t.orden')
                    ->get(['t.id', 't.orden', 't.modo_aplicacion', 't.observaciones', 'e.id as ejercicio_id', 'e.nombre', 'e.url_recurso', 'e.video_path'])
                    ->map(fn ($t): array => [
                        'id' => (int) $t->id, 'orden' => (int) $t->orden,
                        'ejercicio_id' => (int) $t->ejercicio_id, 'nombre' => $t->nombre,
                        'modo_aplicacion' => $t->modo_aplicacion, 'observaciones' => $t->observaciones,
                        'video_url' => $t->video_path ? Storage::disk('public')->url($t->video_path) : $t->url_recurso,
                        'series' => DB::table('entrenamiento.rutina_transferencia_series')->where('transferencia_id', $t->id)
                            ->orderBy('numero_serie')->get(['numero_serie', 'repeticiones', 'tiempo_segundos', 'distancia_metros', 'observaciones']),
                    ])->values();

                return [
                    'id' => $r->id,
                    'ejercicio_id' => $r->ejercicio_id,
                    'nombre' => $r->nombre,
                    'grupo_muscular' => $r->grupo_muscular,
                    'equipamiento' => $r->equipamiento,
                    'instrucciones' => $r->instrucciones,
                    'bloque' => $r->bloque,
                    'orden' => (int) $r->orden,
                    'series' => (int) $r->series,
                    'repeticiones' => $r->repeticiones,
                    'carga_objetivo' => $r->carga_objetivo,
                    'tipo_carga' => $r->tipo_carga,
                    'rm_estimado' => $rm ? (float) $rm->rm_estimado : null,
                    'rm_fecha' => $rm?->fecha_registro,
                    'peso_sugerido' => $rm && $porcentaje !== null
                        ? round(((float) $rm->rm_estimado * $porcentaje) / 100, 2)
                        : null,
                    'peso_sugerido_lb' => $rm && $porcentaje !== null
                        ? round((((float) $rm->rm_estimado * $porcentaje) / 100) * 2.2046226218, 2)
                        : null,
                    'series_planificadas' => $seriesPlanificadas,
                    'transferencias' => $transferencias,
                    'descanso_segundos' => $r->descanso_segundos,
                    'notas' => $r->notas,
                    'video_url' => $r->video_path
                        ? Storage::disk('public')->url($r->video_path)
                        : $r->url_recurso,
                ];
            })->values();

        $totalSemanas = (int) DB::table('entrenamiento.rutinas')
            ->where('plan_id', $plan->id)->max('semana');

        return ApiResponse::exito('Rutina del deportista consultada.', [
            'estado' => 'DISPONIBLE',
            'membresia' => [
                'id' => $membresia->id,
                'nombre' => $membresia->membresia_nombre,
                'fecha_fin' => $membresia->fecha_fin,
            ],
            'plan' => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'objetivo' => $plan->objetivo,
            ],
            'semana' => $semana,
            'dia' => $dia,
            'total_semanas' => max(1, $totalSemanas),
            'ejercicios' => $ejercicios,
        ]);
    }

    private function vacio(string $estado, string $mensaje): JsonResponse
    {
        return ApiResponse::exito($mensaje, [
            'estado' => $estado,
            'mensaje' => $mensaje,
            'membresia' => null,
            'plan' => null,
            'semana' => 1,
            'total_semanas' => 1,
            'ejercicios' => [],
        ]);
    }
}
