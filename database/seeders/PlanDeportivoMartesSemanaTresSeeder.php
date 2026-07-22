<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlanDeportivoMartesSemanaTresSeeder extends Seeder
{
    private const PLAN = 'Plan Deportivo (Grupal)';
    private const SEMANA = 3;
    private const DIA = 'MARTES';

    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now();
            $planId = (int) DB::table('entrenamiento.planes')
                ->where('nombre', self::PLAN)
                ->value('id');

            if (!$planId) {
                throw new RuntimeException('No existe el plan: '.self::PLAN);
            }

            $this->asegurarEjercicios($now);
            $ejercicios = DB::table('entrenamiento.ejercicios')->pluck('id', 'nombre');

            DB::table('entrenamiento.plan_dias')
                ->where('plan_id', $planId)
                ->where('semana', self::SEMANA)
                ->where('dia', self::DIA)
                ->delete();

            $diaId = DB::table('entrenamiento.plan_dias')->insertGetId([
                'plan_id' => $planId,
                'semana' => self::SEMANA,
                'dia' => self::DIA,
                'nombre_sesion' => 'Deportivo etapa 3 - Semana #3 - Martes',
                'observaciones' => 'Carga del martes 2026-07-21 desde pizarra: pliometria, fuerza reactiva, velocidad y transferencias.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $bloques = [
                'Pliometria y coordinacion' => [
                    ['nombre' => 'Intercambios con mancuernas', 'series' => ['20', '20', '20', '20'], 'nota' => 'Pizarra: Intercambio 4x20.'],
                    ['nombre' => 'Dos pies a un pie', 'series' => ['16', '16', '16', '16'], 'nota' => 'Pizarra: 2 pie a 1, 4x16.'],
                    ['nombre' => 'Pogos', 'series' => ['Todo el cesped', 'Todo el cesped', 'Todo el cesped', 'Todo el cesped'], 'nota' => 'Avanzando todo el cesped.'],
                ],
                'Potencia y transferencias' => [
                    ['nombre' => 'Cargada deportiva', 'series' => ['8', '6', '4'], 'nota' => 'Pizarra: Clean power 8-6-4.', 'transferencias' => [
                        ['nombre' => 'Salto al cajón desde Sent.', 'series' => ['6', '6', '6'], 'nota' => 'Transferencia de sentado a cajon alto.'],
                    ]],
                ],
                'Contraste fuerza reactiva' => [
                    ['nombre' => 'Sentadilla', 'usa_rm' => true, 'series' => [
                        ['porcentaje_rm' => 65, 'repeticiones' => '7'],
                        ['porcentaje_rm' => 75, 'repeticiones' => '5'],
                        ['porcentaje_rm' => 85, 'repeticiones' => '3'],
                        ['porcentaje_rm' => 75, 'repeticiones' => '5'],
                    ], 'nota' => 'Contraste de fuerza.', 'transferencias' => [
                        ['nombre' => 'Transferencia cajón medio, cajón alto', 'series' => ['4', '4', '4', '4'], 'nota' => 'Salto pliometrico de cajon bajo a cajon alto.'],
                    ]],
                    ['nombre' => 'Cargada deportiva', 'series' => ['5', '5', '5', '5'], 'nota' => 'Pizarra: Cargada de potencia con salto.', 'transferencias' => [
                        ['nombre' => 'Asistido', 'series' => ['10', '10', '10', '10'], 'nota' => 'Con ligas; lo mas alto posible.'],
                    ]],
                ],
                'Velocidad especifica' => [
                    ['nombre' => 'Tiki-Taka en step con simulacion de remate', 'series' => [
                        ['tiempo_segundos' => 30, 'repeticiones' => '15 remates'],
                        ['tiempo_segundos' => 30, 'repeticiones' => '15 remates'],
                        ['tiempo_segundos' => 30, 'repeticiones' => '15 remates'],
                        ['tiempo_segundos' => 30, 'repeticiones' => '15 remates'],
                    ], 'nota' => 'Tiki-Taka en step 30 segundos + simulacion de remate x15.'],
                ],
            ];

            $ordenBloque = 1;
            foreach ($bloques as $nombreBloque => $items) {
                $bloqueId = DB::table('entrenamiento.plan_bloques')->insertGetId([
                    'plan_dia_id' => $diaId,
                    'nombre' => $nombreBloque,
                    'tipo_bloque' => $nombreBloque,
                    'orden' => $ordenBloque++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($items as $orden => $item) {
                    $this->guardarEjercicio($bloqueId, $orden + 1, $item, $ejercicios, $now);
                }
            }
        });
    }

    private function asegurarEjercicios(Carbon $now): void
    {
        $faltantes = [
            'Tiki-Taka en step con simulacion de remate' => [
                'grupo_muscular' => 'Agilidad',
                'equipamiento' => 'Step',
                'tipo_entrenamiento' => 'Deportivo',
                'instrucciones' => 'Trabajo coordinativo en step seguido de simulacion tecnica de remate.',
            ],
        ];

        foreach ($faltantes as $nombre => $datos) {
            $existe = DB::table('entrenamiento.ejercicios')
                ->whereRaw('lower(btrim(nombre)) = lower(btrim(?))', [$nombre])
                ->exists();

            if (!$existe) {
                DB::table('entrenamiento.ejercicios')->insert(array_merge($datos, [
                    'gimnasio_id' => 1,
                    'nombre' => $nombre,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    private function guardarEjercicio(int $bloqueId, int $orden, array $item, Collection $ejercicios, Carbon $now): void
    {
        $ejercicioId = $ejercicios[$item['nombre']] ?? null;

        if (!$ejercicioId) {
            throw new RuntimeException('No existe el ejercicio requerido: '.$item['nombre']);
        }

        $planEjercicioId = DB::table('entrenamiento.plan_ejercicios')->insertGetId([
            'plan_bloque_id' => $bloqueId,
            'ejercicio_id' => $ejercicioId,
            'orden' => $orden,
            'observaciones' => $item['nota'] ?? null,
            'usa_rm' => $item['usa_rm'] ?? false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($item['series'] as $numero => $serie) {
            $datosSerie = is_array($serie) ? $serie : ['repeticiones' => (string) $serie];
            DB::table('entrenamiento.plan_ejercicio_series')->insert([
                'plan_ejercicio_id' => $planEjercicioId,
                'numero_serie' => $numero + 1,
                'tipo_carga' => isset($datosSerie['porcentaje_rm']) ? 'PORCENTAJE_RM' : 'LIBRE',
                'porcentaje_rm' => $datosSerie['porcentaje_rm'] ?? null,
                'repeticiones' => $datosSerie['repeticiones'] ?? null,
                'tiempo_segundos' => $datosSerie['tiempo_segundos'] ?? null,
                'distancia_metros' => $datosSerie['distancia_metros'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (($item['transferencias'] ?? []) as $ordenTransferencia => $transferencia) {
            $transferenciaId = $ejercicios[$transferencia['nombre']] ?? null;

            if (!$transferenciaId) {
                throw new RuntimeException('No existe la transferencia requerida: '.$transferencia['nombre']);
            }

            $planTransferenciaId = DB::table('entrenamiento.plan_ejercicio_transferencias')->insertGetId([
                'plan_ejercicio_id' => $planEjercicioId,
                'ejercicio_id' => $transferenciaId,
                'orden' => $ordenTransferencia + 1,
                'observaciones' => $transferencia['nota'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($transferencia['series'] as $numero => $serie) {
                $datosSerie = is_array($serie) ? $serie : ['repeticiones' => (string) $serie];
                DB::table('entrenamiento.plan_transferencia_series')->insert([
                    'transferencia_id' => $planTransferenciaId,
                    'numero_serie' => $numero + 1,
                    'tipo_carga' => isset($datosSerie['porcentaje_rm']) ? 'PORCENTAJE_RM' : 'LIBRE',
                    'porcentaje_rm' => $datosSerie['porcentaje_rm'] ?? null,
                    'repeticiones' => $datosSerie['repeticiones'] ?? null,
                    'tiempo_segundos' => $datosSerie['tiempo_segundos'] ?? null,
                    'distancia_metros' => $datosSerie['distancia_metros'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
