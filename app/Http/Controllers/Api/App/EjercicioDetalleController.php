<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EjercicioDetalleController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'plan_id' => 'nullable|integer', 'plan_ejercicio_id' => 'nullable|integer',
            'week' => 'nullable|integer|min:1|max:52', 'day' => 'nullable|string|max:20',
        ]);
        $deportistaId = $this->deportistaId($request);
        $rutinaId = (int) ($datos['plan_ejercicio_id'] ?? 0);
        $rutina = DB::table('entrenamiento.rutinas as r')
            ->join('entrenamiento.planes as p', 'p.id', '=', 'r.plan_id')
            ->join('entrenamiento.ejercicios as e', 'e.id', '=', 'r.ejercicio_id')
            ->where('p.cliente_id', $deportistaId)
            ->where('e.id', $id)
            ->when($rutinaId, fn ($q) => $q->where('r.id', $rutinaId))
            ->first(['r.*', 'p.objetivo as plan_objetivo', 'e.nombre', 'e.grupo_muscular', 'e.equipamiento', 'e.instrucciones', 'e.url_recurso', 'e.video_path']);
        abort_unless($rutina, 404, 'El ejercicio no pertenece a tu plan activo.');

        $historial = DB::table('entrenamiento.sesiones_ejercicios as se')
            ->join('entrenamiento.sesiones as s', 's.id', '=', 'se.sesion_id')
            ->leftJoin('entrenamiento.series_ejecutadas as sr', 'sr.sesion_ejercicio_id', '=', 'se.id')
            ->where('s.cliente_id', $deportistaId)->where('se.ejercicio_id', $id)
            ->groupBy('s.id', 's.fecha_programada', 's.rpe_sesion')->orderByDesc('s.fecha_programada')->limit(8)
            ->get(['s.fecha_programada as fecha', 's.rpe_sesion as rpe', DB::raw('MAX(sr.peso_kg) as carga'), DB::raw('COUNT(sr.id) as series')]);
        $sesionEjercicio = DB::table('entrenamiento.sesiones_ejercicios as se')
            ->join('entrenamiento.sesiones as s', 's.id', '=', 'se.sesion_id')
            ->where('s.cliente_id', $deportistaId)->where('se.rutina_id', $rutina->id)
            ->whereDate('s.fecha_programada', now('America/Guayaquil')->toDateString())->first(['se.id', 's.rpe_sesion', 's.dolor', 's.observaciones']);
        $series = $sesionEjercicio ? DB::table('entrenamiento.series_ejecutadas')->where('sesion_ejercicio_id', $sesionEjercicio->id)->orderBy('numero_serie')->get()
            ->map(fn ($s): array => ['numero_serie' => (int) $s->numero_serie, 'carga' => (float) $s->peso_kg, 'reps' => (int) $s->repeticiones, 'completado' => (bool) $s->completada])->all() : [];
        $instrucciones = array_values(array_filter(array_map('trim', preg_split('/[.;]\s*/', (string) $rutina->instrucciones) ?: [])));

        return response()->json(['data' => [
            'id' => $id, 'plan_id' => (int) $rutina->plan_id, 'plan_ejercicio_id' => (int) $rutina->id,
            'week' => (int) $rutina->semana, 'day' => $rutina->dia, 'name' => $rutina->nombre,
            'tags' => array_values(array_filter([$rutina->grupo_muscular, $rutina->equipamiento])),
            'videoUrl' => $rutina->video_path ? Storage::disk('public')->url($rutina->video_path) : $rutina->url_recurso,
            'instructions' => $instrucciones ?: ['Revisa la postura inicial.', 'Ejecuta el movimiento con control.', 'Respeta la carga y repeticiones planificadas.'],
            'tips' => $rutina->notas ?: 'Prioriza la técnica y detén la serie si aparece dolor.',
            'objetivo' => $rutina->plan_objetivo, 'historial' => $historial,
            'ejecucion' => $sesionEjercicio ? ['series' => $series, 'rpe' => $sesionEjercicio->rpe_sesion, 'dolor_nivel' => $sesionEjercicio->dolor, 'obs' => $sesionEjercicio->observaciones] : null,
        ]]);
    }

    public function ejecutar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'plan_ejercicio_id' => 'required|integer', 'plan_id' => 'required|integer',
            'semana' => 'required|integer|min:1|max:52', 'dia' => 'required|string|max:20',
            'fecha_ejecucion' => 'required|date', 'estado' => 'required|in:PENDIENTE,COMPLETADO,PARCIAL,OMITIDO',
            'series' => 'present|array', 'series.*.numero_serie' => 'required|integer|min:1|max:20',
            'series.*.reps' => 'nullable|numeric|min:0|max:1000', 'series.*.carga' => 'nullable|numeric|min:0|max:2000',
            'series.*.completado' => 'nullable|boolean', 'rpe_real' => 'nullable|numeric|min:1|max:10',
            'dolor_nivel' => 'nullable|integer|min:0|max:10', 'observaciones' => 'nullable|string|max:1000',
        ]);
        $deportistaId = $this->deportistaId($request);
        $rutina = DB::table('entrenamiento.rutinas as r')->join('entrenamiento.planes as p', 'p.id', '=', 'r.plan_id')
            ->where('r.id', $datos['plan_ejercicio_id'])->where('r.plan_id', $datos['plan_id'])->where('p.cliente_id', $deportistaId)->first(['r.*']);
        abort_unless($rutina, 404, 'La prescripción no pertenece a tu plan.');

        return DB::transaction(function () use ($datos, $deportistaId, $rutina): JsonResponse {
            $sesion = DB::table('entrenamiento.sesiones')->where('cliente_id', $deportistaId)->where('plan_id', $rutina->plan_id)
                ->whereDate('fecha_programada', $datos['fecha_ejecucion'])->first();
            $estadoSesion = $datos['estado'] === 'COMPLETADO' ? 'COMPLETADA' : 'EN_PROGRESO';
            if ($sesion) {
                DB::table('entrenamiento.sesiones')->where('id', $sesion->id)->update(['estado' => $estadoSesion, 'rpe_sesion' => $datos['rpe_real'] ?? null, 'dolor' => $datos['dolor_nivel'] ?? null, 'observaciones' => $datos['observaciones'] ?? null, 'updated_at' => now()]);
                $sesionId = $sesion->id;
            } else {
                $sesionId = DB::table('entrenamiento.sesiones')->insertGetId(['cliente_id' => $deportistaId, 'plan_id' => $rutina->plan_id, 'semana' => $datos['semana'], 'dia' => $datos['dia'], 'nombre' => 'Entrenamiento '.$datos['dia'], 'fecha_programada' => $datos['fecha_ejecucion'], 'iniciado_at' => now(), 'finalizado_at' => $estadoSesion === 'COMPLETADA' ? now() : null, 'estado' => $estadoSesion, 'rpe_sesion' => $datos['rpe_real'] ?? null, 'dolor' => $datos['dolor_nivel'] ?? null, 'observaciones' => $datos['observaciones'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $se = DB::table('entrenamiento.sesiones_ejercicios')->where('sesion_id', $sesionId)->where('rutina_id', $rutina->id)->first();
            $seId = $se?->id ?: DB::table('entrenamiento.sesiones_ejercicios')->insertGetId(['sesion_id' => $sesionId, 'rutina_id' => $rutina->id, 'ejercicio_id' => $rutina->ejercicio_id, 'orden' => $rutina->orden, 'series_objetivo' => $rutina->series, 'repeticiones_objetivo' => $rutina->repeticiones, 'carga_objetivo' => $rutina->carga_objetivo, 'tipo_carga' => $rutina->tipo_carga, 'descanso_objetivo_segundos' => $rutina->descanso_segundos, 'bloque' => $rutina->bloque, 'notas' => $rutina->notas, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('entrenamiento.series_ejecutadas')->where('sesion_ejercicio_id', $seId)->delete();
            foreach ($datos['series'] as $serie) {
                DB::table('entrenamiento.series_ejecutadas')->insert(['sesion_ejercicio_id' => $seId, 'numero_serie' => $serie['numero_serie'], 'peso_kg' => $serie['carga'] ?? null, 'repeticiones' => $serie['reps'] ?? null, 'rpe' => $datos['rpe_real'] ?? null, 'completada' => $serie['completado'] ?? true, 'observaciones' => $datos['observaciones'] ?? null, 'registrado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }

            return response()->json(['message' => 'Ejecución registrada correctamente.', 'data' => ['sesion_id' => $sesionId, 'sesion_ejercicio_id' => $seId]]);
        });
    }

    private function deportistaId(Request $request): int
    {
        $id = DB::table('clientes.deportistas')->where('usuario_id', $request->user()->id)->value('id');
        abort_unless($id, 403, 'La cuenta no tiene un deportista vinculado.');

        return (int) $id;
    }
}
