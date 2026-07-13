<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanHibridoDeportivoSeeder extends Seeder
{
    private const PLAN_HIBRIDO = 'Plan Muscular Híbrido (Grupal)';
    private const PLAN_DEPORTIVO = 'Plan Deportivo (Grupal)';

    public function run(): void
    {
        $now = Carbon::now();

        $this->seedEjercicios($now);

        $ejercicios = DB::table('entrenamiento.ejercicios')->pluck('id', 'nombre');

        $planHibridoId = $this->upsertPlan(
            self::PLAN_HIBRIDO,
            'HIBRIDO',
            'Trabajo grupal hibrido etapa 3: fuerza, hipertrofia, contraste y potencia.',
            'Transcripcion aproximada desde pizarra: Hibrido etapa 3, semana #2.',
            $now
        );

        $planDeportivoId = $this->upsertPlan(
            self::PLAN_DEPORTIVO,
            'DEPORTIVO',
            'Trabajo grupal deportivo etapa 3: pliometria, fuerza reactiva, velocidad y transferencias.',
            'Transcripcion aproximada desde pizarra: Deportivo etapa 3, semana #2.',
            $now
        );

        DB::table('entrenamiento.plan_dias')->whereIn('plan_id', [$planHibridoId, $planDeportivoId])->delete();

        $this->guardarDia($planHibridoId, 2, 'LUNES', 'Hibrido etapa 3 - Semana #2', [
            'Activacion' => [
                ['nombre' => 'Pogos con mancuernas', 'series' => ['40', '30', '20', '20']],
                ['nombre' => 'Caminata puntilla y biceps con mancuernas', 's' => 4, 'r' => 'ida y vuelta'],
                ['nombre' => 'Intercambios con mancuernas', 's' => 4, 'r' => '2 por lado', 'transferencias' => [
                    ['nombre' => 'Sentadilla con contrapeso', 's' => 4, 'r' => '15'],
                ]],
            ],
            'Fuerza superior' => [
                ['nombre' => 'S T', 'series' => ['14', '12', '10', '8'], 'transferencias' => [
                    ['nombre' => 'Predicador', 's' => 4, 'r' => '10 pesado'],
                ]],
                ['nombre' => 'Contraste inercia', 's' => 1, 'r' => 'Trabajo tecnico'],
            ],
            'Pierna' => [
                ['nombre' => 'Sentadilla', 'series' => [
                    ['porcentaje_rm' => 65, 'repeticiones' => '14'],
                    ['porcentaje_rm' => 70, 'repeticiones' => '10'],
                    ['porcentaje_rm' => 80, 'repeticiones' => '6'],
                    ['porcentaje_rm' => 80, 'repeticiones' => '6'],
                ], 'usa_rm' => true],
            ],
            'Potencia y cierre' => [
                ['nombre' => 'Spot piso sentado', 's' => 4, 'r' => '10'],
                ['nombre' => 'Clean squat caminata pesada', 's' => 4, 'r' => '8'],
                ['nombre' => 'Burpees piso explosivo', 's' => 4, 'r' => '10'],
                ['nombre' => 'Asistido', 's' => 1, 'r' => '20'],
            ],
        ], $ejercicios);

        $this->guardarDia($planDeportivoId, 2, 'LUNES', 'Deportivo etapa 3 - Semana #2', [
            'Pliometria y coordinacion' => [
                ['nombre' => 'Tijeras', 'series' => ['30', '20', '10', '16']],
                ['nombre' => 'Pogos', 'series' => ['40', '30', '20', '10']],
                ['nombre' => 'Dos pies a un pie', 'series' => ['20', '16', '16', '12'], 'nota' => 'Por lado'],
                ['nombre' => 'Laterales', 's' => 4, 'r' => '16', 'nota' => 'Solo peso'],
            ],
            'Explosivo' => [
                ['nombre' => 'Pliometria con tiradas a la pared', 's' => 1, 'tiempo_segundos' => 30],
                ['nombre' => 'Squat jump con fondos de triceps', 's' => 4, 'r' => '5'],
                ['nombre' => 'Clase remo explosiva', 's' => 4, 'r' => '6'],
                ['nombre' => 'Transferencia tres saltos y squash', 's' => 3, 'r' => '4'],
            ],
            'Fuerza' => [
                ['nombre' => 'Sentadilla', 'series' => [
                    ['porcentaje_rm' => 60, 'repeticiones' => '5'],
                    ['porcentaje_rm' => 70, 'repeticiones' => '5'],
                    ['porcentaje_rm' => 80, 'repeticiones' => '3'],
                ], 'usa_rm' => true],
            ],
            'Velocidad' => [
                ['nombre' => '10 metros explosivos', 's' => 10, 'distancia_metros' => 10],
            ],
        ], $ejercicios);
    }

    private function seedEjercicios(Carbon $now): void
    {
        $ejercicios = [
            ['nombre' => 'Pogos con mancuernas', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Caminata puntilla y biceps con mancuernas', 'grupo_muscular' => 'Piernas/Brazos', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Intercambios con mancuernas', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Sentadilla con contrapeso', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Mancuerna', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'S T', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Varios', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Predicador', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Banco/Maquina', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Contraste inercia', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Varios', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Sentadilla', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Barra Libre', 'tipo_entrenamiento' => 'Fuerza'],
            ['nombre' => 'Spot piso sentado', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Potencia'],
            ['nombre' => 'Clean squat caminata pesada', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Barra/Mancuernas', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Burpees piso explosivo', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Asistido', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Soporte', 'tipo_entrenamiento' => 'Hibrido'],
            ['nombre' => 'Tijeras', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Pogos', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Dos pies a un pie', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Laterales', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Pliometria con tiradas a la pared', 'grupo_muscular' => 'Pecho/Core', 'equipamiento' => 'Balon Medicinal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Squat jump con fondos de triceps', 'grupo_muscular' => 'Piernas/Brazos', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Clase remo explosiva', 'grupo_muscular' => 'Espalda', 'equipamiento' => 'Varios', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Transferencia tres saltos y squash', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => '10 metros explosivos', 'grupo_muscular' => 'Velocidad', 'equipamiento' => 'Ninguno', 'tipo_entrenamiento' => 'Deportivo'],
        ];

        foreach ($ejercicios as $ejercicio) {
            DB::table('entrenamiento.ejercicios')->updateOrInsert(
                ['nombre' => $ejercicio['nombre']],
                array_merge($ejercicio, [
                    'gimnasio_id' => 1,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    private function upsertPlan(string $nombre, string $tipo, string $objetivo, string $observaciones, Carbon $now): int
    {
        DB::table('entrenamiento.planes')->updateOrInsert(
            ['nombre' => $nombre],
            [
                'persona_id' => null,
                'objetivo' => $objetivo,
                'fecha_inicio' => $now->toDateString(),
                'fecha_fin' => $now->copy()->addWeeks(4)->toDateString(),
                'estado' => 'ACTIVO',
                'observaciones' => $observaciones,
                'tipo' => $tipo,
                'alcance' => 'GRUPAL',
                'estructura' => 'SEMANAL',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) DB::table('entrenamiento.planes')->where('nombre', $nombre)->value('id');
    }

    private function guardarDia(int $planId, int $semana, string $dia, string $nombreSesion, array $bloques, $ejercicios): void
    {
        $diaId = DB::table('entrenamiento.plan_dias')->insertGetId([
            'plan_id' => $planId,
            'semana' => $semana,
            'dia' => $dia,
            'nombre_sesion' => $nombreSesion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ordenBloque = 1;
        foreach ($bloques as $nombreBloque => $items) {
            $bloqueId = DB::table('entrenamiento.plan_bloques')->insertGetId([
                'plan_dia_id' => $diaId,
                'nombre' => $nombreBloque,
                'tipo_bloque' => $nombreBloque,
                'orden' => $ordenBloque++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $ordenEjercicio => $item) {
                $this->guardarEjercicio($bloqueId, $ordenEjercicio + 1, $item, $ejercicios);
            }
        }
    }

    private function guardarEjercicio(int $bloqueId, int $orden, array $item, $ejercicios): void
    {
        $ejercicioId = $ejercicios[$item['nombre']] ?? null;

        if (!$ejercicioId) {
            return;
        }

        $planEjercicioId = DB::table('entrenamiento.plan_ejercicios')->insertGetId([
            'plan_bloque_id' => $bloqueId,
            'ejercicio_id' => $ejercicioId,
            'orden' => $orden,
            'observaciones' => $item['nota'] ?? null,
            'usa_rm' => $item['usa_rm'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->normalizarSeries($item) as $numeroSerie => $serie) {
            DB::table('entrenamiento.plan_ejercicio_series')->insert([
                'plan_ejercicio_id' => $planEjercicioId,
                'numero_serie' => $numeroSerie + 1,
                'tipo_carga' => isset($serie['porcentaje_rm']) ? 'PORCENTAJE_RM' : 'LIBRE',
                'porcentaje_rm' => $serie['porcentaje_rm'] ?? null,
                'repeticiones' => $serie['repeticiones'] ?? null,
                'tiempo_segundos' => $serie['tiempo_segundos'] ?? null,
                'distancia_metros' => $serie['distancia_metros'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (($item['transferencias'] ?? []) as $ordenTransferencia => $transferencia) {
            $transferenciaId = $ejercicios[$transferencia['nombre']] ?? null;
            if (!$transferenciaId) {
                continue;
            }

            $planTransferenciaId = DB::table('entrenamiento.plan_ejercicio_transferencias')->insertGetId([
                'plan_ejercicio_id' => $planEjercicioId,
                'ejercicio_id' => $transferenciaId,
                'orden' => $ordenTransferencia + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($this->normalizarSeries($transferencia) as $numeroSerie => $serie) {
                DB::table('entrenamiento.plan_transferencia_series')->insert([
                    'transferencia_id' => $planTransferenciaId,
                    'numero_serie' => $numeroSerie + 1,
                    'repeticiones' => $serie['repeticiones'] ?? null,
                    'tiempo_segundos' => $serie['tiempo_segundos'] ?? null,
                    'distancia_metros' => $serie['distancia_metros'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function normalizarSeries(array $item): array
    {
        if (isset($item['series'])) {
            return array_map(function ($serie) {
                return is_array($serie) ? $serie : ['repeticiones' => (string) $serie];
            }, $item['series']);
        }

        $cantidad = (int) ($item['s'] ?? 1);
        $series = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $series[] = [
                'repeticiones' => $item['r'] ?? null,
                'tiempo_segundos' => $item['tiempo_segundos'] ?? null,
                'distancia_metros' => $item['distancia_metros'] ?? null,
            ];
        }

        return $series;
    }
}
