<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MiActividadController extends Controller
{
    public function rms(Request $request): JsonResponse
    {
        $id = $this->deportistaId($request);
        $datos = $id ? DB::table('entrenamiento.rm_registros as rm')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'rm.ejercicio_id')
            ->where('rm.cliente_id', $id)->orderByDesc('rm.fecha_registro')->orderBy('e.nombre')
            ->get(['rm.id', 'e.nombre as ejercicio', 'e.grupo_muscular', 'e.equipamiento', 'e.url_recurso', 'e.video_path', 'rm.tipo_registro', 'rm.peso', 'rm.repeticiones', 'rm.repeticiones as repeticiones_usadas', 'rm.rm_estimado', 'rm.fecha_registro', 'rm.observaciones'])
            ->map(function ($rm): array {
                $estimado = (float) $rm->rm_estimado;

                return array_merge((array) $rm, [
                    'rm_estimado' => $estimado,
                    'rm_estimado_lb' => round($estimado * 2.2046226218, 2),
                    'peso_lb' => $rm->peso !== null ? round((float) $rm->peso * 2.2046226218, 2) : null,
                    'cargas_porcentaje' => collect([50, 60, 70, 75, 80, 85, 90, 95, 100])->map(fn (int $porcentaje): array => [
                        'porcentaje' => $porcentaje,
                        'kg' => round($estimado * $porcentaje / 100, 2),
                        'lb' => round($estimado * $porcentaje / 100 * 2.2046226218, 2),
                    ])->all(),
                ]);
            })->values() : collect();

        return response()->json(['data' => $datos]);
    }

    public function fichas(Request $request): JsonResponse
    {
        $id = $this->deportistaId($request);
        $evaluaciones = $id ? DB::table('entrenamiento.progresos_corporales')->where('cliente_id', $id)->orderByDesc('fecha_registro')->get()->map(fn ($p) => [
            'id' => $p->id, 'fecha_ficha' => $p->fecha_registro, 'fecha_registro' => $p->fecha_registro,
            'peso_kg' => (float) $p->peso_kg, 'talla_cm' => (float) $p->talla_cm, 'cintura_cm' => (float) $p->cintura_cm,
            'grasa_corporal_pct' => (float) $p->grasa_corporal_pct, 'masa_magra_kg' => (float) $p->masa_magra_kg,
            'imc' => (float) $p->imc, 'cintura_altura' => $p->talla_cm ? round((float) $p->cintura_cm / (float) $p->talla_cm, 3) : null,
            'objetivo' => $p->objetivo, 'observaciones' => $p->observaciones, 'estado' => 'COMPLETADA',
        ])->values() : collect();

        return response()->json(['data' => ['ficha' => $evaluaciones->first(), 'evaluaciones' => $evaluaciones]]);
    }

    public function progreso(Request $request): JsonResponse
    {
        $id = $this->deportistaId($request);
        if (! $id) {
            return response()->json(['totalWeeks' => 0, 'performance' => $this->performanceVacio()]);
        }
        $filtro = (string) $request->query('filter', 'todo');
        $sesiones = DB::table('entrenamiento.sesiones')->where('cliente_id', $id)->where('estado', 'COMPLETADA');
        if (preg_match('/^semana_(\d+)$/', $filtro, $m)) {
            $sesiones->where('semana', (int) $m[1]);
        }
        $ids = $sesiones->pluck('id');
        $filas = DB::table('entrenamiento.sesiones_ejercicios as se')
            ->join('entrenamiento.sesiones as s', 's.id', '=', 'se.sesion_id')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'se.ejercicio_id')
            ->leftJoin('entrenamiento.series_ejecutadas as serie', 'serie.sesion_ejercicio_id', '=', 'se.id')
            ->whereIn('se.sesion_id', $ids)
            ->select('s.semana', 's.dia', 'e.nombre', 'se.id', 'se.series_objetivo', 'se.repeticiones_objetivo', 'serie.numero_serie', 'serie.peso_kg', 'serie.repeticiones', 'serie.completada')
            ->orderBy('s.semana')->orderBy('se.id')->orderBy('serie.numero_serie')->get();
        $semanas = $filas->groupBy('semana')->map(function ($items, $semana): array {
            $ejercicios = $items->groupBy('id')->map(function ($series): array {
                $primera = $series->first();
                $repsPlan = (int) $primera->repeticiones_objetivo;
                $seriesPlan = (int) $primera->series_objetivo;
                $repsReales = (int) $series->where('completada', true)->sum('repeticiones');

                return ['id' => $primera->id, 'name' => $primera->nombre, 'day' => $primera->dia,
                    'plannedReps' => $seriesPlan * $repsPlan, 'actualReps' => $repsReales,
                    'compliance' => $seriesPlan * $repsPlan > 0 ? round($repsReales / ($seriesPlan * $repsPlan) * 100) : 0,
                    'plannedSeries' => collect(range(1, $seriesPlan))->map(fn ($n) => ['number' => $n, 'reps' => $repsPlan, 'load' => 0])->all(),
                    'actualSeries' => $series->where('completada', true)->map(fn ($s) => ['number' => $s->numero_serie, 'reps' => (int) $s->repeticiones, 'load' => (float) $s->peso_kg])->values()->all()];
            })->values();

            return ['label' => 'Semana '.$semana, 'plannedReps' => $ejercicios->sum('plannedReps'), 'actualReps' => $ejercicios->sum('actualReps'), 'exercises' => $ejercicios->all()];
        })->values();
        $series = DB::table('entrenamiento.series_ejecutadas as serie')->join('entrenamiento.sesiones_ejercicios as se', 'se.id', '=', 'serie.sesion_ejercicio_id')->whereIn('se.sesion_id', $ids)->where('serie.completada', true)->get(['serie.peso_kg', 'serie.repeticiones']);
        $totalReps = (int) $series->sum('repeticiones');
        $planned = (int) $semanas->sum('plannedReps');
        $performance = ['hasLoadData' => $series->where('peso_kg', '>', 0)->isNotEmpty(), 'totalReps' => $totalReps,
            'plannedReps' => $planned, 'repsCompliance' => $planned ? round($totalReps / $planned * 100) : 0,
            'averageLoad' => round((float) $series->avg('peso_kg'), 1), 'maxLoad' => (float) $series->max('peso_kg'),
            'totalVolume' => round((float) $series->sum(fn ($s) => (float) $s->peso_kg * (int) $s->repeticiones), 1),
            'volumeEvolution' => $semanas->map(fn ($s, $i) => ['label' => 'S'.($i + 1), 'value' => $s['actualReps']])->all(),
            'comparisonEvolution' => $semanas->all()];

        return response()->json(['totalWeeks' => 4, 'performance' => $performance]);
    }

    private function deportistaId(Request $request): ?int
    {
        $id = DB::table('clientes.deportistas')->where('usuario_id', $request->user()->id)->value('id');

        return $id ? (int) $id : null;
    }

    private function performanceVacio(): array
    {
        return ['hasLoadData' => false, 'totalReps' => 0, 'plannedReps' => 0, 'repsCompliance' => 0, 'averageLoad' => 0, 'maxLoad' => 0, 'totalVolume' => 0, 'volumeEvolution' => [], 'comparisonEvolution' => []];
    }
}
