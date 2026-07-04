<?php

namespace App\Http\Controllers\Entrenamiento;

use App\Http\Controllers\Controller;
use App\Queries\Entrenamiento\PlanEntrenamientoQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlanEntrenamientoController extends Controller
{
    public function __construct(private PlanEntrenamientoQuery $query)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->query->listarPlanes($request->only(['buscar', 'estado', 'persona_id'])));
    }

    public function show(int $id)
    {
        $detalle = $this->query->obtenerDetallePlan($id);

        if (!$detalle) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        return response()->json($detalle);
    }

    public function personasDisponibles()
    {
        return response()->json($this->query->listarPersonasDisponibles());
    }

    public function store(Request $request)
    {
        $data = $this->validatePlan($request);

        $id = DB::table('entrenamiento.planes')->insertGetId([
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Plan creado correctamente.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validatePlan($request);

        $updated = DB::table('entrenamiento.planes')
            ->where('id', $id)
            ->update([
                ...$data,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        return response()->json(['message' => 'Plan actualizado correctamente.']);
    }

    public function destroy(int $id)
    {
        $deleted = DB::table('entrenamiento.planes')
            ->where('id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        return response()->json(['message' => 'Plan eliminado correctamente.']);
    }

    public function assignments(int $planId)
    {
        return response()->json($this->query->listarAsignacionesPlan($planId));
    }

    public function storeAssignment(Request $request, int $planId)
    {
        $plan = DB::table('entrenamiento.planes')->where('id', $planId)->first();
        if (!$plan) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        $data = $this->validateAssignment($request, $plan->alcance ?? 'GRUPAL', true);

        if (!empty($data['persona_ids'])) {
            $insertData = [];
            foreach ($data['persona_ids'] as $pid) {
                $insertData[] = [
                    'plan_id' => $planId,
                    'alcance' => $plan->alcance ?? 'GRUPAL',
                    'persona_id' => $pid,
                    'nombre_grupo' => $data['nombre_grupo'] ?? null,
                    'fecha_inicio' => $data['fecha_inicio'] ?? null,
                    'fecha_fin' => $data['fecha_fin'] ?? null,
                    'estado' => $data['estado'] ?? 'ACTIVO',
                    'observaciones' => $data['observaciones'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('entrenamiento.plan_asignaciones')->insert($insertData);
            return response()->json(['message' => count($insertData) . ' asignaciones creadas correctamente.'], 201);
        } else {
            $id = DB::table('entrenamiento.plan_asignaciones')->insertGetId([
                'plan_id' => $planId,
                'alcance' => $plan->alcance ?? 'GRUPAL',
                'persona_id' => $data['persona_id'] ?? null,
                'nombre_grupo' => $data['nombre_grupo'] ?? null,
                'fecha_inicio' => $data['fecha_inicio'] ?? null,
                'fecha_fin' => $data['fecha_fin'] ?? null,
                'estado' => $data['estado'] ?? 'ACTIVO',
                'observaciones' => $data['observaciones'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return response()->json(['message' => 'Asignación del plan registrada correctamente.', 'id' => $id], 201);
        }
    }

    public function syncGroupAssignment(Request $request, int $planId)
    {
        $plan = DB::table('entrenamiento.planes')->where('id', $planId)->first();
        if (!$plan) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        $request->merge($this->sanitizePayload($request->all()));
        $data = $request->validate([
            'nombre_grupo_original' => ['required', 'string', 'max:120'],
            'nombre_grupo' => ['required', 'string', 'max:120'],
            'persona_ids' => ['nullable', 'array'],
            'persona_ids.*' => ['integer'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($planId, $plan, $data) {
            DB::table('entrenamiento.plan_asignaciones')
                ->where('plan_id', $planId)
                ->where('alcance', 'GRUPAL')
                ->where('nombre_grupo', $data['nombre_grupo_original'])
                ->delete();

            if (!empty($data['persona_ids'])) {
                $insertData = [];
                foreach ($data['persona_ids'] as $pid) {
                    $insertData[] = [
                        'plan_id' => $planId,
                        'alcance' => 'GRUPAL',
                        'persona_id' => $pid,
                        'nombre_grupo' => $data['nombre_grupo'],
                        'fecha_inicio' => $data['fecha_inicio'] ?? null,
                        'fecha_fin' => $data['fecha_fin'] ?? null,
                        'estado' => $data['estado'] ?? 'ACTIVO',
                        'observaciones' => $data['observaciones'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('entrenamiento.plan_asignaciones')->insert($insertData);
            } else {
                DB::table('entrenamiento.plan_asignaciones')->insert([
                    'plan_id' => $planId,
                    'alcance' => 'GRUPAL',
                    'persona_id' => null,
                    'nombre_grupo' => $data['nombre_grupo'],
                    'fecha_inicio' => $data['fecha_inicio'] ?? null,
                    'fecha_fin' => $data['fecha_fin'] ?? null,
                    'estado' => $data['estado'] ?? 'ACTIVO',
                    'observaciones' => $data['observaciones'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Grupo actualizado correctamente.']);
    }

    public function updateAssignment(Request $request, int $planId, int $assignmentId)
    {
        $plan = DB::table('entrenamiento.planes')->where('id', $planId)->first();
        if (!$plan) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        $data = $this->validateAssignment($request, $plan->alcance ?? 'GRUPAL');

        $updated = DB::table('entrenamiento.plan_asignaciones')
            ->where('id', $assignmentId)
            ->where('plan_id', $planId)
            ->update([
                'persona_id' => $data['persona_id'] ?? null,
                'nombre_grupo' => $data['nombre_grupo'] ?? null,
                'fecha_inicio' => $data['fecha_inicio'] ?? null,
                'fecha_fin' => $data['fecha_fin'] ?? null,
                'estado' => $data['estado'] ?? 'ACTIVO',
                'observaciones' => $data['observaciones'] ?? null,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró la asignación del plan solicitada.'], 404);
        }

        return response()->json(['message' => 'Asignación del plan actualizada correctamente.']);
    }

    public function destroyAssignment(int $planId, int $assignmentId)
    {
        $deleted = DB::table('entrenamiento.plan_asignaciones')
            ->where('id', $assignmentId)
            ->where('plan_id', $planId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró la asignación del plan solicitada.'], 404);
        }

        return response()->json(['message' => 'Asignación del plan eliminada correctamente.']);
    }

    public function destroyGroupAssignment(Request $request, int $planId)
    {
        $request->merge($this->sanitizePayload($request->all()));
        $nombreGrupo = $request->input('nombre_grupo');

        if (!$nombreGrupo) {
            return response()->json(['message' => 'Nombre del grupo requerido.'], 400);
        }

        $deleted = DB::table('entrenamiento.plan_asignaciones')
            ->where('plan_id', $planId)
            ->where('alcance', 'GRUPAL')
            ->where('nombre_grupo', $nombreGrupo)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró el grupo solicitado.'], 404);
        }

        return response()->json(['message' => 'Grupo eliminado correctamente.']);
    }

    public function syncDay(Request $request, int $planId)
    {
        $request->merge($this->sanitizePayload($request->all()));

        $payload = $request->validate([
            'semana' => ['required', 'integer', 'min:1'],
            'dia' => ['required', 'string', 'max:30'],
            'nombre_sesion' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string'],
            'bloques' => ['present', 'array'],
            'bloques.*.nombre' => ['required', 'string', 'max:120'],
            'bloques.*.tipo_bloque' => ['nullable', 'string', 'max:60'],
            'bloques.*.orden' => ['nullable', 'integer', 'min:1'],
            'bloques.*.observaciones' => ['nullable', 'string'],
            'bloques.*.ejercicios' => ['present', 'array'],
            'bloques.*.ejercicios.*.ejercicio_id' => ['required', 'integer'],
            'bloques.*.ejercicios.*.orden' => ['nullable', 'integer', 'min:1'],
            'bloques.*.ejercicios.*.lado' => ['nullable', 'string', 'max:20'],
            'bloques.*.ejercicios.*.observaciones' => ['nullable', 'string'],
            'bloques.*.ejercicios.*.usa_rm' => ['nullable', 'boolean'],
            'bloques.*.ejercicios.*.rm_referencia' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.rm_registro_id' => ['nullable', 'integer'],
            'bloques.*.ejercicios.*.modo_prescripcion' => ['nullable', 'string', 'max:30'],
            'bloques.*.ejercicios.*.descanso_segundos' => ['nullable', 'integer', 'min:0'],
            'bloques.*.ejercicios.*.tempo' => ['nullable', 'string', 'max:30'],
            'bloques.*.ejercicios.*.rpe_objetivo' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'bloques.*.ejercicios.*.series' => ['present', 'array'],
            'bloques.*.ejercicios.*.series.*.numero_serie' => ['required', 'integer', 'min:1'],
            'bloques.*.ejercicios.*.series.*.tipo_carga' => ['nullable', 'string', 'max:30'],
            'bloques.*.ejercicios.*.series.*.porcentaje_rm' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.series.*.carga_fija' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.series.*.unidad_carga' => ['nullable', 'string', 'max:20'],
            'bloques.*.ejercicios.*.series.*.repeticiones' => ['nullable', 'string', 'max:50'],
            'bloques.*.ejercicios.*.series.*.tiempo_segundos' => ['nullable', 'integer', 'min:0'],
            'bloques.*.ejercicios.*.series.*.distancia_metros' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.series.*.rpe' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'bloques.*.ejercicios.*.series.*.descanso_segundos' => ['nullable', 'integer', 'min:0'],
            'bloques.*.ejercicios.*.series.*.tempo' => ['nullable', 'string', 'max:30'],
            'bloques.*.ejercicios.*.series.*.observaciones' => ['nullable', 'string'],
            'bloques.*.ejercicios.*.transferencias' => ['present', 'array'],
            'bloques.*.ejercicios.*.transferencias.*.ejercicio_id' => ['required', 'integer'],
            'bloques.*.ejercicios.*.transferencias.*.orden' => ['nullable', 'integer', 'min:1'],
            'bloques.*.ejercicios.*.transferencias.*.modo_aplicacion' => ['nullable', 'string', 'max:30'],
            'bloques.*.ejercicios.*.transferencias.*.observaciones' => ['nullable', 'string'],
            'bloques.*.ejercicios.*.transferencias.*.series' => ['present', 'array'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.numero_serie' => ['required', 'integer', 'min:1'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.tipo_carga' => ['nullable', 'string', 'max:30'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.porcentaje_rm' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.carga_fija' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.unidad_carga' => ['nullable', 'string', 'max:20'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.repeticiones' => ['nullable', 'string', 'max:50'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.tiempo_segundos' => ['nullable', 'integer', 'min:0'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.distancia_metros' => ['nullable', 'numeric', 'min:0'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.rpe' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'bloques.*.ejercicios.*.transferencias.*.series.*.observaciones' => ['nullable', 'string'],
        ]);

        $existsPlan = DB::table('entrenamiento.planes')->where('id', $planId)->exists();
        if (!$existsPlan) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        DB::transaction(function () use ($planId, $payload) {
            $day = DB::table('entrenamiento.plan_dias')
                ->where('plan_id', $planId)
                ->where('semana', $payload['semana'])
                ->where('dia', $payload['dia'])
                ->first();

            if ($day) {
                DB::table('entrenamiento.plan_dias')
                    ->where('id', $day->id)
                    ->update([
                        'nombre_sesion' => $payload['nombre_sesion'] ?? null,
                        'observaciones' => $payload['observaciones'] ?? null,
                        'updated_at' => now(),
                    ]);
                $dayId = (int) $day->id;

                DB::table('entrenamiento.plan_bloques')->where('plan_dia_id', $dayId)->delete();
            } else {
                $dayId = DB::table('entrenamiento.plan_dias')->insertGetId([
                    'plan_id' => $planId,
                    'semana' => $payload['semana'],
                    'dia' => $payload['dia'],
                    'nombre_sesion' => $payload['nombre_sesion'] ?? null,
                    'observaciones' => $payload['observaciones'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($payload['bloques'] as $blockIndex => $bloque) {
                $blockId = DB::table('entrenamiento.plan_bloques')->insertGetId([
                    'plan_dia_id' => $dayId,
                    'nombre' => $bloque['nombre'],
                    'tipo_bloque' => $bloque['tipo_bloque'] ?? null,
                    'orden' => $bloque['orden'] ?? ($blockIndex + 1),
                    'observaciones' => $bloque['observaciones'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($bloque['ejercicios'] as $exerciseIndex => $ejercicio) {
                    $exerciseId = DB::table('entrenamiento.plan_ejercicios')->insertGetId([
                        'plan_bloque_id' => $blockId,
                        'ejercicio_id' => $ejercicio['ejercicio_id'],
                        'orden' => $ejercicio['orden'] ?? ($exerciseIndex + 1),
                        'lado' => $ejercicio['lado'] ?? null,
                        'observaciones' => $ejercicio['observaciones'] ?? null,
                        'usa_rm' => (bool) ($ejercicio['usa_rm'] ?? false),
                        'rm_referencia' => $ejercicio['rm_referencia'] ?? null,
                        'rm_registro_id' => $ejercicio['rm_registro_id'] ?? null,
                        'modo_prescripcion' => $ejercicio['modo_prescripcion'] ?? 'POR_SERIE',
                        'descanso_segundos' => $ejercicio['descanso_segundos'] ?? null,
                        'tempo' => $ejercicio['tempo'] ?? null,
                        'rpe_objetivo' => $ejercicio['rpe_objetivo'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($ejercicio['series'] as $serie) {
                        DB::table('entrenamiento.plan_ejercicio_series')->insert([
                            'plan_ejercicio_id' => $exerciseId,
                            'numero_serie' => $serie['numero_serie'],
                            'tipo_carga' => $serie['tipo_carga'] ?? 'LIBRE',
                            'porcentaje_rm' => $serie['porcentaje_rm'] ?? null,
                            'carga_fija' => $serie['carga_fija'] ?? null,
                            'unidad_carga' => $serie['unidad_carga'] ?? null,
                            'repeticiones' => $serie['repeticiones'] ?? null,
                            'tiempo_segundos' => $serie['tiempo_segundos'] ?? null,
                            'distancia_metros' => $serie['distancia_metros'] ?? null,
                            'rpe' => $serie['rpe'] ?? null,
                            'descanso_segundos' => $serie['descanso_segundos'] ?? null,
                            'tempo' => $serie['tempo'] ?? null,
                            'observaciones' => $serie['observaciones'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    foreach ($ejercicio['transferencias'] as $transferIndex => $transferencia) {
                        $transferId = DB::table('entrenamiento.plan_ejercicio_transferencias')->insertGetId([
                            'plan_ejercicio_id' => $exerciseId,
                            'ejercicio_id' => $transferencia['ejercicio_id'],
                            'orden' => $transferencia['orden'] ?? ($transferIndex + 1),
                            'modo_aplicacion' => $transferencia['modo_aplicacion'] ?? 'POR_CADA_SERIE',
                            'observaciones' => $transferencia['observaciones'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        foreach ($transferencia['series'] as $serieTransferencia) {
                            DB::table('entrenamiento.plan_transferencia_series')->insert([
                                'transferencia_id' => $transferId,
                                'numero_serie' => $serieTransferencia['numero_serie'],
                                'tipo_carga' => $serieTransferencia['tipo_carga'] ?? 'LIBRE',
                                'porcentaje_rm' => $serieTransferencia['porcentaje_rm'] ?? null,
                                'carga_fija' => $serieTransferencia['carga_fija'] ?? null,
                                'unidad_carga' => $serieTransferencia['unidad_carga'] ?? null,
                                'repeticiones' => $serieTransferencia['repeticiones'] ?? null,
                                'tiempo_segundos' => $serieTransferencia['tiempo_segundos'] ?? null,
                                'distancia_metros' => $serieTransferencia['distancia_metros'] ?? null,
                                'rpe' => $serieTransferencia['rpe'] ?? null,
                                'observaciones' => $serieTransferencia['observaciones'] ?? null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }
        });

        return response()->json(['message' => 'Día del plan sincronizado correctamente.']);
    }

    public function destroyDay(int $planId, int $dayId)
    {
        $deleted = DB::table('entrenamiento.plan_dias')
            ->where('id', $dayId)
            ->where('plan_id', $planId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No se encontró el día solicitado.'], 404);
        }

        return response()->json(['message' => 'Día eliminado correctamente.']);
    }

    public function duplicateWeek(Request $request, int $planId)
    {
        $payload = $request->validate([
            'semana_origen' => ['required', 'integer', 'min:1'],
            'semana_destino' => ['required', 'integer', 'min:1', 'different:semana_origen'],
        ]);

        $existsPlan = DB::table('entrenamiento.planes')->where('id', $planId)->exists();
        if (!$existsPlan) {
            return response()->json(['message' => 'No se encontró el plan solicitado.'], 404);
        }

        $diasOrigen = DB::table('entrenamiento.plan_dias')
            ->where('plan_id', $planId)
            ->where('semana', $payload['semana_origen'])
            ->orderByRaw("
                CASE dia
                    WHEN 'LUNES' THEN 1
                    WHEN 'MARTES' THEN 2
                    WHEN 'MIERCOLES' THEN 3
                    WHEN 'JUEVES' THEN 4
                    WHEN 'VIERNES' THEN 5
                    WHEN 'SABADO' THEN 6
                    WHEN 'DOMINGO' THEN 7
                    ELSE 8
                END
            ")
            ->orderBy('id')
            ->get();

        if ($diasOrigen->isEmpty()) {
            return response()->json(['message' => 'La semana origen no tiene días configurados para duplicar.'], 422);
        }

        DB::transaction(function () use ($planId, $payload, $diasOrigen) {
            DB::table('entrenamiento.plan_dias')
                ->where('plan_id', $planId)
                ->where('semana', $payload['semana_destino'])
                ->delete();

            foreach ($diasOrigen as $diaOrigen) {
                $nuevoDiaId = DB::table('entrenamiento.plan_dias')->insertGetId([
                    'plan_id' => $planId,
                    'semana' => $payload['semana_destino'],
                    'dia' => $diaOrigen->dia,
                    'nombre_sesion' => $diaOrigen->nombre_sesion,
                    'observaciones' => $diaOrigen->observaciones,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $bloquesOrigen = DB::table('entrenamiento.plan_bloques')
                    ->where('plan_dia_id', $diaOrigen->id)
                    ->orderBy('orden')
                    ->orderBy('id')
                    ->get();

                foreach ($bloquesOrigen as $bloqueOrigen) {
                    $nuevoBloqueId = DB::table('entrenamiento.plan_bloques')->insertGetId([
                        'plan_dia_id' => $nuevoDiaId,
                        'nombre' => $bloqueOrigen->nombre,
                        'tipo_bloque' => $bloqueOrigen->tipo_bloque,
                        'orden' => $bloqueOrigen->orden,
                        'observaciones' => $bloqueOrigen->observaciones,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $ejerciciosOrigen = DB::table('entrenamiento.plan_ejercicios')
                        ->where('plan_bloque_id', $bloqueOrigen->id)
                        ->orderBy('orden')
                        ->orderBy('id')
                        ->get();

                    foreach ($ejerciciosOrigen as $ejercicioOrigen) {
                        $nuevoEjercicioId = DB::table('entrenamiento.plan_ejercicios')->insertGetId([
                            'plan_bloque_id' => $nuevoBloqueId,
                            'ejercicio_id' => $ejercicioOrigen->ejercicio_id,
                            'orden' => $ejercicioOrigen->orden,
                            'lado' => $ejercicioOrigen->lado,
                            'observaciones' => $ejercicioOrigen->observaciones,
                            'usa_rm' => $ejercicioOrigen->usa_rm,
                            'rm_referencia' => $ejercicioOrigen->rm_referencia,
                            'rm_registro_id' => $ejercicioOrigen->rm_registro_id,
                            'modo_prescripcion' => $ejercicioOrigen->modo_prescripcion,
                            'descanso_segundos' => $ejercicioOrigen->descanso_segundos,
                            'tempo' => $ejercicioOrigen->tempo,
                            'rpe_objetivo' => $ejercicioOrigen->rpe_objetivo,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $seriesOrigen = DB::table('entrenamiento.plan_ejercicio_series')
                            ->where('plan_ejercicio_id', $ejercicioOrigen->id)
                            ->orderBy('numero_serie')
                            ->orderBy('id')
                            ->get();

                        foreach ($seriesOrigen as $serieOrigen) {
                            DB::table('entrenamiento.plan_ejercicio_series')->insert([
                                'plan_ejercicio_id' => $nuevoEjercicioId,
                                'numero_serie' => $serieOrigen->numero_serie,
                                'tipo_carga' => $serieOrigen->tipo_carga,
                                'porcentaje_rm' => $serieOrigen->porcentaje_rm,
                                'carga_fija' => $serieOrigen->carga_fija,
                                'unidad_carga' => $serieOrigen->unidad_carga,
                                'repeticiones' => $serieOrigen->repeticiones,
                                'tiempo_segundos' => $serieOrigen->tiempo_segundos,
                                'distancia_metros' => $serieOrigen->distancia_metros,
                                'rpe' => $serieOrigen->rpe,
                                'descanso_segundos' => $serieOrigen->descanso_segundos,
                                'tempo' => $serieOrigen->tempo,
                                'observaciones' => $serieOrigen->observaciones,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        $transferenciasOrigen = DB::table('entrenamiento.plan_ejercicio_transferencias')
                            ->where('plan_ejercicio_id', $ejercicioOrigen->id)
                            ->orderBy('orden')
                            ->orderBy('id')
                            ->get();

                        foreach ($transferenciasOrigen as $transferenciaOrigen) {
                            $nuevaTransferenciaId = DB::table('entrenamiento.plan_ejercicio_transferencias')->insertGetId([
                                'plan_ejercicio_id' => $nuevoEjercicioId,
                                'ejercicio_id' => $transferenciaOrigen->ejercicio_id,
                                'orden' => $transferenciaOrigen->orden,
                                'modo_aplicacion' => $transferenciaOrigen->modo_aplicacion,
                                'observaciones' => $transferenciaOrigen->observaciones,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $seriesTransferenciaOrigen = DB::table('entrenamiento.plan_transferencia_series')
                                ->where('transferencia_id', $transferenciaOrigen->id)
                                ->orderBy('numero_serie')
                                ->orderBy('id')
                                ->get();

                            foreach ($seriesTransferenciaOrigen as $serieTransferenciaOrigen) {
                                DB::table('entrenamiento.plan_transferencia_series')->insert([
                                    'transferencia_id' => $nuevaTransferenciaId,
                                    'numero_serie' => $serieTransferenciaOrigen->numero_serie,
                                    'tipo_carga' => $serieTransferenciaOrigen->tipo_carga,
                                    'porcentaje_rm' => $serieTransferenciaOrigen->porcentaje_rm,
                                    'carga_fija' => $serieTransferenciaOrigen->carga_fija,
                                    'unidad_carga' => $serieTransferenciaOrigen->unidad_carga,
                                    'repeticiones' => $serieTransferenciaOrigen->repeticiones,
                                    'tiempo_segundos' => $serieTransferenciaOrigen->tiempo_segundos,
                                    'distancia_metros' => $serieTransferenciaOrigen->distancia_metros,
                                    'rpe' => $serieTransferenciaOrigen->rpe,
                                    'observaciones' => $serieTransferenciaOrigen->observaciones,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }
            }
        });

        return response()->json(['message' => 'Semana duplicada correctamente.']);
    }

    private function validatePlan(Request $request): array
    {
        $request->merge($this->sanitizePayload($request->all()));

        $data = $request->validate([
            'persona_id' => ['nullable', 'integer'],
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', 'string', 'max:50'],
            'alcance' => ['nullable', 'string', 'max:20'],
            'estructura' => ['required', 'string', 'max:30'],
            'objetivo' => ['nullable', 'string'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string'],
        ]);

        if (!Schema::hasColumn('entrenamiento.planes', 'alcance')) {
            unset($data['alcance']);
        }

        return $data;
    }

    private function validateAssignment(Request $request, string $alcance, bool $isStore = false): array
    {
        $request->merge($this->sanitizePayload($request->all()));

        $rules = [
            'persona_id' => ['nullable', 'integer'],
            'persona_ids' => ['nullable', 'array'],
            'persona_ids.*' => ['integer'],
            'nombre_grupo' => ['nullable', 'string', 'max:120'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string'],
        ];

        if ($alcance === 'INDIVIDUAL') {
            if ($isStore) {
                $rules['persona_id'] = ['required_without:persona_ids', 'nullable', 'integer'];
                $rules['persona_ids'] = ['required_without:persona_id', 'nullable', 'array', 'min:1'];
            } else {
                $rules['persona_id'] = ['required', 'integer'];
            }
        } else {
            $rules['nombre_grupo'] = ['required', 'string', 'max:120'];
            if ($isStore) {
                $rules['persona_id'] = ['nullable', 'integer'];
                $rules['persona_ids'] = ['nullable', 'array'];
            } else {
                $rules['persona_id'] = ['nullable', 'integer'];
            }
        }

        return $request->validate($rules);
    }

    private function sanitizePayload(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizePayload($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }
}
