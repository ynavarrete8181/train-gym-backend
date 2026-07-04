<?php

namespace App\Http\Controllers\Entrenamiento;

use App\Http\Controllers\Controller;
use App\Queries\Entrenamiento\PlantillaSemanalQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlantillaSemanalController extends Controller
{
    public function __construct(private PlantillaSemanalQuery $query)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->query->listar($request->only(['buscar', 'activa'])));
    }

    public function show(int $id)
    {
        $data = $this->query->obtener($id);
        if (!$data) {
            return response()->json(['message' => 'No se encontró la plantilla semanal solicitada.'], 404);
        }
        return response()->json($data);
    }

    public function store(Request $request)
    {
        $request->merge($this->sanitizePayload($request->all()));

        $payload = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'objetivo' => ['nullable', 'string'],
            'disciplina' => ['nullable', 'string', 'max:80'],
            'total_dias' => ['required', 'integer', 'min:1', 'max:7'],
            'activa' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $id = DB::table('entrenamiento.plantillas_semanales')->insertGetId([
            ...$payload,
            'activa' => $payload['activa'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Plantilla semanal creada correctamente.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id)
    {
        $request->merge($this->sanitizePayload($request->all()));

        $payload = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'objetivo' => ['nullable', 'string'],
            'disciplina' => ['nullable', 'string', 'max:80'],
            'total_dias' => ['required', 'integer', 'min:1', 'max:7'],
            'activa' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $updated = DB::table('entrenamiento.plantillas_semanales')
            ->where('id', $id)
            ->update([
                ...$payload,
                'activa' => $payload['activa'] ?? true,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json(['message' => 'No se encontró la plantilla semanal solicitada.'], 404);
        }

        return response()->json(['message' => 'Plantilla semanal actualizada correctamente.']);
    }

    public function syncDay(Request $request, int $plantillaId)
    {
        $request->merge($this->sanitizePayload($request->all()));

        $payload = $request->validate([
            'orden_dia' => ['required', 'integer', 'min:1', 'max:7'],
            'dia' => ['required', 'string', 'max:30'],
            'nombre_sesion' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string'],
            'bloques' => ['present', 'array'],
            'bloques.*.nombre' => ['required', 'string', 'max:120'],
            'bloques.*.tipo_bloque' => ['nullable', 'string', 'max:60'],
            'bloques.*.orden' => ['nullable', 'integer', 'min:1'],
            'bloques.*.observaciones' => ['nullable', 'string'],
            'bloques.*.ejercicios' => ['present', 'array'],
            'bloques.*.ejercicios.*.ejercicio_id' => ['nullable', 'integer'],
            'bloques.*.ejercicios.*.nombre_libre' => ['nullable', 'string', 'max:150'],
            'bloques.*.ejercicios.*.orden' => ['nullable', 'integer', 'min:1'],
            'bloques.*.ejercicios.*.lado' => ['nullable', 'string', 'max:20'],
            'bloques.*.ejercicios.*.observaciones' => ['nullable', 'string'],
            'bloques.*.ejercicios.*.usa_rm' => ['nullable', 'boolean'],
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
            'bloques.*.ejercicios.*.transferencias.*.ejercicio_id' => ['nullable', 'integer'],
            'bloques.*.ejercicios.*.transferencias.*.nombre_libre' => ['nullable', 'string', 'max:150'],
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

        DB::transaction(function () use ($plantillaId, $payload) {
            $dia = DB::table('entrenamiento.plantilla_semana_dias')
                ->where('plantilla_id', $plantillaId)
                ->where('orden_dia', $payload['orden_dia'])
                ->where('dia', $payload['dia'])
                ->first();

            if ($dia) {
                DB::table('entrenamiento.plantilla_semana_dias')->where('id', $dia->id)->update([
                    'nombre_sesion' => $payload['nombre_sesion'] ?? null,
                    'observaciones' => $payload['observaciones'] ?? null,
                    'updated_at' => now(),
                ]);
                $diaId = (int) $dia->id;
                DB::table('entrenamiento.plantilla_semana_bloques')->where('plantilla_dia_id', $diaId)->delete();
            } else {
                $diaId = DB::table('entrenamiento.plantilla_semana_dias')->insertGetId([
                    'plantilla_id' => $plantillaId,
                    'orden_dia' => $payload['orden_dia'],
                    'dia' => $payload['dia'],
                    'nombre_sesion' => $payload['nombre_sesion'] ?? null,
                    'observaciones' => $payload['observaciones'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($payload['bloques'] as $blockIndex => $bloque) {
                $bloqueId = DB::table('entrenamiento.plantilla_semana_bloques')->insertGetId([
                    'plantilla_dia_id' => $diaId,
                    'nombre' => $bloque['nombre'],
                    'tipo_bloque' => $bloque['tipo_bloque'] ?? null,
                    'orden' => $bloque['orden'] ?? ($blockIndex + 1),
                    'observaciones' => $bloque['observaciones'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($bloque['ejercicios'] as $exerciseIndex => $ejercicio) {
                    if (empty($ejercicio['ejercicio_id']) && empty($ejercicio['nombre_libre'])) {
                        continue;
                    }

                    $ejercicioId = DB::table('entrenamiento.plantilla_semana_ejercicios')->insertGetId([
                        'plantilla_bloque_id' => $bloqueId,
                        'ejercicio_id' => $ejercicio['ejercicio_id'] ?? null,
                        'nombre_libre' => $ejercicio['nombre_libre'] ?? null,
                        'orden' => $ejercicio['orden'] ?? ($exerciseIndex + 1),
                        'lado' => $ejercicio['lado'] ?? null,
                        'observaciones' => $ejercicio['observaciones'] ?? null,
                        'usa_rm' => (bool) ($ejercicio['usa_rm'] ?? false),
                        'modo_prescripcion' => $ejercicio['modo_prescripcion'] ?? 'POR_SERIE',
                        'descanso_segundos' => $ejercicio['descanso_segundos'] ?? null,
                        'tempo' => $ejercicio['tempo'] ?? null,
                        'rpe_objetivo' => $ejercicio['rpe_objetivo'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($ejercicio['series'] as $serie) {
                        DB::table('entrenamiento.plantilla_semana_ejercicio_series')->insert([
                            'plantilla_ejercicio_id' => $ejercicioId,
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
                        if (empty($transferencia['ejercicio_id']) && empty($transferencia['nombre_libre'])) {
                            continue;
                        }

                        $transferId = DB::table('entrenamiento.plantilla_semana_ejercicio_transferencias')->insertGetId([
                            'plantilla_ejercicio_id' => $ejercicioId,
                            'ejercicio_id' => $transferencia['ejercicio_id'] ?? null,
                            'nombre_libre' => $transferencia['nombre_libre'] ?? null,
                            'orden' => $transferencia['orden'] ?? ($transferIndex + 1),
                            'modo_aplicacion' => $transferencia['modo_aplicacion'] ?? 'POR_CADA_SERIE',
                            'observaciones' => $transferencia['observaciones'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        foreach ($transferencia['series'] as $serieTransferencia) {
                            DB::table('entrenamiento.plantilla_semana_transferencia_series')->insert([
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

        return response()->json(['message' => 'Día de plantilla sincronizado correctamente.']);
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
