<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use App\Queries\Personas\PlanRutinaQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanRutinaController extends Controller
{
    public function __construct(private PlanRutinaQuery $query)
    {
    }

    public function indexPlanes(Request $request)
    {
        return response()->json($this->query->listarPlanes($request->only(['buscar', 'estado'])));
    }

    private function validateRutina(Request $request): array
    {
        return $request->validate([
            'semana' => ['required', 'integer', 'min:1'],
            'dia' => ['required', 'string', 'max:30'],
            'bloque' => ['nullable', 'string', 'max:120'],
            'ejercicio_id' => ['required', 'integer'],
            'series' => ['required', 'integer', 'min:1'],
            'repeticiones' => ['nullable', 'string', 'max:50'],
            'carga_objetivo' => ['nullable', 'numeric', 'min:0'],
            'tipo_carga' => ['nullable', 'string', 'max:30'],
            'unidad_objetivo' => ['nullable', 'string', 'max:20'],
            'tempo' => ['nullable', 'string', 'max:30'],
            'rpe' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'descanso_segundos' => ['nullable', 'integer', 'min:0'],
            'bloque_orden' => ['nullable', 'integer', 'min:1'],
            'orden' => ['nullable', 'integer', 'min:1'],
            'notas' => ['nullable', 'string'],
            'ejercicio_transferencia_id' => ['nullable', 'integer'],
            'repeticiones_transferencia' => ['nullable', 'integer', 'min:0'],
            'series_detalles' => ['nullable', 'array'],
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:150'],
            'objetivo' => ['nullable', 'string'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $id = DB::table('entrenamiento.planes')->insertGetId([
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Plan guardado correctamente.', 'id' => $id], 201);
    }

    public function updatePlan(Request $request, $id)
    {
        $data = $request->validate([
            'persona_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:150'],
            'objetivo' => ['nullable', 'string'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $updated = DB::table('entrenamiento.planes')->where('id', $id)->update([
            ...$data,
            'updated_at' => now(),
        ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró the plan to update.'], 404);
        }

        return response()->json(['message' => 'Plan actualizado correctamente.']);
    }

    public function destroyPlan($id)
    {
        $deleted = DB::table('entrenamiento.planes')->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró el plan a eliminar.'], 404);
        }

        return response()->json(['message' => 'Plan eliminado correctamente.']);
    }

    public function duplicatePlan(Request $request, $id)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'estado' => ['nullable', 'string', 'max:30'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
        ]);

        $plan = DB::table('entrenamiento.planes')->where('id', $id)->first();

        if (!$plan) {
            return response()->json(['message' => 'No se encontró el plan a duplicar.'], 404);
        }

        $newPlanId = DB::transaction(function () use ($plan, $data) {
            $newPlanId = DB::table('entrenamiento.planes')->insertGetId([
                'persona_id' => $plan->persona_id,
                'nombre' => $data['nombre'],
                'objetivo' => $plan->objetivo,
                'fecha_inicio' => $data['fecha_inicio'] ?? $plan->fecha_inicio,
                'fecha_fin' => $data['fecha_fin'] ?? $plan->fecha_fin,
                'estado' => $data['estado'] ?? 'BORRADOR',
                'observaciones' => $plan->observaciones,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rutinas = DB::table('entrenamiento.rutinas')->where('plan_id', $plan->id)->get();

            foreach ($rutinas as $rutina) {
                DB::table('entrenamiento.rutinas')->insert([
                    'plan_id' => $newPlanId,
                    'semana' => $rutina->semana,
                    'dia' => $rutina->dia,
                    'bloque' => $rutina->bloque,
                    'ejercicio_id' => $rutina->ejercicio_id,
                    'series' => $rutina->series,
                    'repeticiones' => $rutina->repeticiones,
                    'carga_objetivo' => $rutina->carga_objetivo,
                    'tipo_carga' => $rutina->tipo_carga,
                    'unidad_objetivo' => $rutina->unidad_objetivo ?? null,
                    'tempo' => $rutina->tempo ?? null,
                    'rpe' => $rutina->rpe ?? null,
                    'descanso_segundos' => $rutina->descanso_segundos,
                    'bloque_orden' => $rutina->bloque_orden ?? 1,
                    'orden' => $rutina->orden ?? 1,
                    'notas' => $rutina->notes ?? $rutina->notas ?? null,
                    'ejercicio_transferencia_id' => $rutina->ejercicio_transferencia_id ?? null,
                    'repeticiones_transferencia' => $rutina->repeticiones_transferencia ?? null,
                    'series_detalles' => $rutina->series_detalles ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $newPlanId;
        });

        return response()->json([
            'message' => 'Plan duplicado correctamente.',
            'id' => $newPlanId,
        ], 201);
    }

    public function indexRutinas($planId)
    {
        return response()->json($this->query->listarRutinasPorPlan((int) $planId));
    }

    public function storeRutina(Request $request, $planId)
    {
        $data = $this->validateRutina($request);

        $id = DB::table('entrenamiento.rutinas')->insertGetId([
            'plan_id' => $planId,
            'semana' => $data['semana'],
            'dia' => $data['dia'],
            'bloque' => $data['bloque'] ?? null,
            'ejercicio_id' => $data['ejercicio_id'],
            'series' => $data['series'],
            'repeticiones' => $data['repeticiones'] ?? null,
            'carga_objetivo' => $data['carga_objetivo'] ?? null,
            'tipo_carga' => $data['tipo_carga'] ?? 'LIBRE',
            'unidad_objetivo' => $data['unidad_objetivo'] ?? null,
            'tempo' => $data['tempo'] ?? null,
            'rpe' => $data['rpe'] ?? null,
            'descanso_segundos' => $data['descanso_segundos'] ?? null,
            'bloque_orden' => $data['bloque_orden'] ?? 1,
            'orden' => $data['orden'] ?? 1,
            'notas' => $data['notas'] ?? null,
            'ejercicio_transferencia_id' => $data['ejercicio_transferencia_id'] ?? null,
            'repeticiones_transferencia' => $data['repeticiones_transferencia'] ?? null,
            'series_detalles' => isset($data['series_detalles']) ? json_encode($data['series_detalles']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Rutina guardada correctamente.', 'id' => $id], 201);
    }

    public function updateRutina(Request $request, $planId, $id)
    {
        $data = $this->validateRutina($request);

        $updated = DB::table('entrenamiento.rutinas')
            ->where('id', $id)
            ->where('plan_id', $planId)
            ->update([
                'semana' => $data['semana'],
                'dia' => $data['dia'],
                'bloque' => $data['bloque'] ?? null,
                'ejercicio_id' => $data['ejercicio_id'],
                'series' => $data['series'],
                'repeticiones' => $data['repeticiones'] ?? null,
                'carga_objetivo' => $data['carga_objetivo'] ?? null,
                'tipo_carga' => $data['tipo_carga'] ?? 'LIBRE',
                'unidad_objetivo' => $data['unidad_objetivo'] ?? null,
                'tempo' => $data['tempo'] ?? null,
                'rpe' => $data['rpe'] ?? null,
                'descanso_segundos' => $data['descanso_segundos'] ?? null,
                'bloque_orden' => $data['bloque_orden'] ?? 1,
                'orden' => $data['orden'] ?? 1,
                'notas' => $data['notas'] ?? null,
                'ejercicio_transferencia_id' => $data['ejercicio_transferencia_id'] ?? null,
                'repeticiones_transferencia' => $data['repeticiones_transferencia'] ?? null,
                'series_detalles' => isset($data['series_detalles']) ? json_encode($data['series_detalles']) : null,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró la rutina a actualizar.'], 404);
        }

        return response()->json(['message' => 'Rutina actualizada correctamente.']);
    }

    public function destroyRutina($planId, $id)
    {
        $deleted = DB::table('entrenamiento.rutinas')
            ->where('id', $id)
            ->where('plan_id', $planId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró la rutina a eliminar.'], 404);
        }

        return response()->json(['message' => 'Rutina eliminada correctamente.']);
    }

    public function duplicateSemana(Request $request, $planId)
    {
        $data = $request->validate([
            'semana_origen' => ['required', 'integer', 'min:1'],
            'semana_destino' => ['required', 'integer', 'min:1', 'different:semana_origen'],
        ]);

        $plan = DB::table('entrenamiento.planes')->where('id', $planId)->first();

        if (!$plan) {
            return response()->json(['message' => 'No se encontró el plan indicado.'], 404);
        }

        $rutinasOrigen = DB::table('entrenamiento.rutinas')
            ->where('plan_id', $planId)
            ->where('semana', $data['semana_origen'])
            ->get();

        if ($rutinasOrigen->isEmpty()) {
            return response()->json(['message' => 'La semana origen no tiene rutinas para duplicar.'], 422);
        }

        $yaExisteDestino = DB::table('entrenamiento.rutinas')
            ->where('plan_id', $planId)
            ->where('semana', $data['semana_destino'])
            ->exists();

        if ($yaExisteDestino) {
            return response()->json(['message' => 'La semana destino ya tiene rutinas registradas.'], 422);
        }

        DB::transaction(function () use ($rutinasOrigen, $planId, $data) {
            foreach ($rutinasOrigen as $rutina) {
                DB::table('entrenamiento.rutinas')->insert([
                    'plan_id' => $planId,
                    'semana' => $data['semana_destino'],
                    'dia' => $rutina->dia,
                    'bloque' => $rutina->bloque,
                    'ejercicio_id' => $rutina->ejercicio_id,
                    'series' => $rutina->series,
                    'repeticiones' => $rutina->repeticiones,
                    'carga_objetivo' => $rutina->carga_objetivo,
                    'tipo_carga' => $rutina->tipo_carga,
                    'unidad_objetivo' => $rutina->unidad_objetivo ?? null,
                    'tempo' => $rutina->tempo ?? null,
                    'rpe' => $rutina->rpe ?? null,
                    'descanso_segundos' => $rutina->descanso_segundos,
                    'bloque_orden' => $rutina->bloque_orden ?? 1,
                    'orden' => $rutina->orden ?? 1,
                    'notas' => $rutina->notas,
                    'ejercicio_transferencia_id' => $rutina->ejercicio_transferencia_id ?? null,
                    'repeticiones_transferencia' => $rutina->repeticiones_transferencia ?? null,
                    'series_detalles' => $rutina->series_detalles ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Semana duplicada correctamente.']);
    }

    public function indexPlantillas()
    {
        return response()->json($this->query->listarPlantillas());
    }

    public function saveTemplateFromWeek(Request $request, $planId)
    {
        $data = $request->validate([
            'semana_origen' => ['required', 'integer', 'min:1'],
            'nombre' => ['required', 'string', 'max:150'],
            'objetivo' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $rutinasOrigen = DB::table('entrenamiento.rutinas')
            ->where('plan_id', $planId)
            ->where('semana', $data['semana_origen'])
            ->orderBy('dia')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        if ($rutinasOrigen->isEmpty()) {
            return response()->json(['message' => 'La semana origen no tiene rutinas para guardar como plantilla.'], 422);
        }

        $plantillaId = DB::transaction(function () use ($rutinasOrigen, $data) {
            $plantillaId = DB::table('entrenamiento.rutina_plantillas')->insertGetId([
                'nombre' => $data['nombre'],
                'objetivo' => $data['objetivo'] ?? null,
                'descripcion' => $data['descripcion'] ?? null,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($rutinasOrigen as $rutina) {
                DB::table('entrenamiento.rutina_plantilla_detalles')->insert([
                    'plantilla_id' => $plantillaId,
                    'dia' => $rutina->dia,
                    'bloque' => $rutina->bloque,
                    'ejercicio_id' => $rutina->ejercicio_id,
                    'series' => $rutina->series,
                    'repeticiones' => $rutina->repeticiones,
                    'carga_objetivo' => $rutina->carga_objetivo,
                    'tipo_carga' => $rutina->tipo_carga,
                    'unidad_objetivo' => $rutina->unidad_objetivo ?? null,
                    'tempo' => $rutina->tempo ?? null,
                    'rpe' => $rutina->rpe ?? null,
                    'descanso_segundos' => $rutina->descanso_segundos,
                    'bloque_orden' => $rutina->bloque_orden ?? 1,
                    'orden' => $rutina->orden ?? 1,
                    'notas' => $rutina->notas,
                    'ejercicio_transferencia_id' => $rutina->ejercicio_transferencia_id ?? null,
                    'repeticiones_transferencia' => $rutina->repeticiones_transferencia ?? null,
                    'series_detalles' => $rutina->series_detalles ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $plantillaId;
        });

        return response()->json(['message' => 'Plantilla guardada correctamente.', 'id' => $plantillaId], 201);
    }

    public function applyTemplateToPlan(Request $request, $planId)
    {
        $data = $request->validate([
            'plantilla_id' => ['required', 'integer'],
            'semana_destino' => ['required', 'integer', 'min:1'],
        ]);

        $plan = DB::table('entrenamiento.planes')->where('id', $planId)->first();
        if (!$plan) {
            return response()->json(['message' => 'No se encontró el plan indicado.'], 404);
        }

        $detalles = DB::table('entrenamiento.rutina_plantilla_detalles')
            ->where('plantilla_id', $data['plantilla_id'])
            ->orderBy('dia')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        if ($detalles->isEmpty()) {
            return response()->json(['message' => 'La plantilla seleccionada no tiene ejercicios configurados.'], 422);
        }

        $yaExisteDestino = DB::table('entrenamiento.rutinas')
            ->where('plan_id', $planId)
            ->where('semana', $data['semana_destino'])
            ->exists();

        if ($yaExisteDestino) {
            return response()->json(['message' => 'La semana destino ya tiene rutinas registradas.'], 422);
        }

        DB::transaction(function () use ($detalles, $planId, $data) {
            foreach ($detalles as $detalle) {
                DB::table('entrenamiento.rutinas')->insert([
                    'plan_id' => $planId,
                    'semana' => $data['semana_destino'],
                    'dia' => $detalle->dia,
                    'bloque' => $detalle->bloque,
                    'ejercicio_id' => $detalle->ejercicio_id,
                    'series' => $detalle->series,
                    'repeticiones' => $detalle->repeticiones,
                    'carga_objetivo' => $detalle->carga_objetivo,
                    'tipo_carga' => $detalle->tipo_carga,
                    'unidad_objetivo' => $detalle->unidad_objetivo,
                    'tempo' => $detalle->tempo,
                    'rpe' => $detalle->rpe,
                    'descanso_segundos' => $detalle->descanso_segundos,
                    'bloque_orden' => $detalle->bloque_orden ?? 1,
                    'orden' => $detalle->orden ?? 1,
                    'notas' => $detalle->notas,
                    'ejercicio_transferencia_id' => $detalle->ejercicio_transferencia_id ?? null,
                    'repeticiones_transferencia' => $detalle->repeticiones_transferencia ?? null,
                    'series_detalles' => $detalle->series_detalles ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Plantilla aplicada correctamente.']);
    }

    public function destroyTemplate($id)
    {
        $deleted = DB::table('entrenamiento.rutina_plantillas')->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró la plantilla a eliminar.'], 404);
        }

        return response()->json(['message' => 'Plantilla eliminada correctamente.']);
    }

    public function saveBatchRutinas(Request $request, $planId)
    {
        $payload = $request->validate([
            'semana' => ['required', 'integer', 'min:1'],
            'dia' => ['required', 'string', 'max:30'],
            'rutinas' => ['present', 'array'],
            'rutinas.*.id' => ['nullable', 'integer'],
            'rutinas.*.bloque' => ['nullable', 'string', 'max:120'],
            'rutinas.*.ejercicio_id' => ['required', 'integer'],
            'rutinas.*.series' => ['required', 'integer', 'min:1'],
            'rutinas.*.repeticiones' => ['nullable', 'string', 'max:50'],
            'rutinas.*.carga_objetivo' => ['nullable', 'numeric', 'min:0'],
            'rutinas.*.tipo_carga' => ['nullable', 'string', 'max:30'],
            'rutinas.*.unidad_objetivo' => ['nullable', 'string', 'max:20'],
            'rutinas.*.tempo' => ['nullable', 'string', 'max:30'],
            'rutinas.*.rpe' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'rutinas.*.descanso_segundos' => ['nullable', 'integer', 'min:0'],
            'rutinas.*.bloque_orden' => ['nullable', 'integer', 'min:1'],
            'rutinas.*.orden' => ['nullable', 'integer', 'min:1'],
            'rutinas.*.notas' => ['nullable', 'string'],
            'rutinas.*.ejercicio_transferencia_id' => ['nullable', 'integer'],
            'rutinas.*.repeticiones_transferencia' => ['nullable', 'integer', 'min:0'],
            'rutinas.*.series_detalles' => ['nullable', 'array'],
        ]);

        $semana = $payload['semana'];
        $dia = $payload['dia'];
        $rutinasInput = $payload['rutinas'];

        DB::transaction(function () use ($planId, $semana, $dia, $rutinasInput) {
            $dbRutinaIds = DB::table('entrenamiento.rutinas')
                ->where('plan_id', $planId)
                ->where('semana', $semana)
                ->where('dia', $dia)
                ->pluck('id')
                ->toArray();

            $inputIds = collect($rutinasInput)->pluck('id')->filter()->toArray();
            $idsToDelete = array_diff($dbRutinaIds, $inputIds);

            if (!empty($idsToDelete)) {
                DB::table('entrenamiento.rutinas')->whereIn('id', $idsToDelete)->delete();
            }

            foreach ($rutinasInput as $index => $item) {
                $orden = $item['orden'] ?? ($index + 1);
                $data = [
                    'plan_id' => $planId,
                    'semana' => $semana,
                    'dia' => $dia,
                    'bloque' => $item['bloque'] ?? null,
                    'ejercicio_id' => $item['ejercicio_id'],
                    'series' => $item['series'],
                    'repeticiones' => $item['repeticiones'] ?? null,
                    'carga_objetivo' => $item['carga_objetivo'] ?? null,
                    'tipo_carga' => $item['tipo_carga'] ?? 'LIBRE',
                    'unidad_objetivo' => $item['unidad_objetivo'] ?? null,
                    'tempo' => $item['tempo'] ?? null,
                    'rpe' => $item['rpe'] ?? null,
                    'descanso_segundos' => $item['descanso_segundos'] ?? null,
                    'bloque_orden' => $item['bloque_orden'] ?? 1,
                    'orden' => $orden,
                    'notas' => $item['notas'] ?? null,
                    'ejercicio_transferencia_id' => $item['ejercicio_transferencia_id'] ?? null,
                    'repeticiones_transferencia' => $item['repeticiones_transferencia'] ?? null,
                    'series_detalles' => isset($item['series_detalles']) ? json_encode($item['series_detalles']) : null,
                    'updated_at' => now(),
                ];

                if (!empty($item['id'])) {
                    DB::table('entrenamiento.rutinas')
                        ->where('id', $item['id'])
                        ->where('plan_id', $planId)
                        ->update($data);
                } else {
                    $data['created_at'] = now();
                    DB::table('entrenamiento.rutinas')->insert($data);
                }
            }
        });

        return response()->json(['message' => 'Rutinas sincronizadas correctamente.']);
    }
}
