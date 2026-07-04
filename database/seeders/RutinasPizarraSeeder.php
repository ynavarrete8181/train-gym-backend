<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RutinasPizarraSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $gimnasio_id = 1;

        // 1. REGISTRAR EJERCICIOS DE LA PIZARRA
        $ejerciciosNuevos = [
            // Híbridos
            ['nombre' => 'Balón plio colchoneta negra', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Balón Medicinal', 'tipo_entrenamiento' => 'Híbrido'],
            ['nombre' => 'Balón plio colchoneta verde', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Balón Medicinal', 'tipo_entrenamiento' => 'Híbrido'],
            ['nombre' => 'Liga flex. pecho', 'grupo_muscular' => 'Pecho', 'equipamiento' => 'Bandas de Resistencia', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Pecho inclinado a una mano', 'grupo_muscular' => 'Pecho', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Vuelos frontales cabo', 'grupo_muscular' => 'Hombros', 'equipamiento' => 'Polea', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Press pecho', 'grupo_muscular' => 'Pecho', 'equipamiento' => 'Barra Libre', 'tipo_entrenamiento' => 'Fuerza'],
            ['nombre' => 'Vuelos laterales', 'grupo_muscular' => 'Hombros', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Burpees', 'grupo_muscular' => 'Cardio', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Press hombro', 'grupo_muscular' => 'Hombros', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Step flex. ida y vuelta', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Cajón/Step', 'tipo_entrenamiento' => 'Híbrido'],
            
            // Más Híbridos
            ['nombre' => 'Biceps barra', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Barra Libre', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Tríceps copa', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Biceps concentrado', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Triceps cabo', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Polea', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Predicador', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Máquina/Banco', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Cabo desde la cabeza', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Polea', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Extensión', 'grupo_muscular' => 'Cuerpo', 'equipamiento' => 'Varios', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Tijera pasos cortos con Manc.', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Frances', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Barra/Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Sentadilla', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Barra Libre', 'tipo_entrenamiento' => 'Fuerza'],
            ['nombre' => 'Salto al cajón desde Sent.', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Cajón Pliométrico', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Clean squat', 'grupo_muscular' => 'Cuerpo Completo', 'equipamiento' => 'Barra/Mancuernas', 'tipo_entrenamiento' => 'Híbrido'],
            ['nombre' => 'Asist. a una pierna', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Bandas/Soporte', 'tipo_entrenamiento' => 'Muscular'],

            // Deportivos
            ['nombre' => 'Empuje de balón desde piso', 'grupo_muscular' => 'Pecho/Core', 'equipamiento' => 'Balón Medicinal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Remo barra', 'grupo_muscular' => 'Espalda', 'equipamiento' => 'Barra Libre', 'tipo_entrenamiento' => 'Fuerza'],
            ['nombre' => 'Liga remate', 'grupo_muscular' => 'Cuerpo', 'equipamiento' => 'Bandas de Resistencia', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Dominadas', 'grupo_muscular' => 'Espalda', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Liga biceps expl.', 'grupo_muscular' => 'Brazos', 'equipamiento' => 'Bandas de Resistencia', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Press de hombro arrodillado', 'grupo_muscular' => 'Hombros', 'equipamiento' => 'Mancuernas', 'tipo_entrenamiento' => 'Muscular'],
            ['nombre' => 'Lanzamiento balón a pared', 'grupo_muscular' => 'Core/Hombros', 'equipamiento' => 'Balón Medicinal', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Carreras 10mts', 'grupo_muscular' => 'Cardio', 'equipamiento' => 'Ninguno', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Pogo jump con una pierna', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Cajón/Step', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Lateral y pl cajon', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Cajón/Step', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Salto lateral tipos pogos', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Ninguno', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Habilidad de juego', 'grupo_muscular' => 'Agilidad', 'equipamiento' => 'Varios', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Kettlebell squat jump', 'grupo_muscular' => 'Piernas', 'equipamiento' => 'Kettlebell', 'tipo_entrenamiento' => 'Deportivo'],
            ['nombre' => 'Salto tijera tipo chavo', 'grupo_muscular' => 'Cardio', 'equipamiento' => 'Peso Corporal', 'tipo_entrenamiento' => 'Deportivo'],
        ];

        foreach ($ejerciciosNuevos as $ej) {
            DB::table('entrenamiento.ejercicios')->updateOrInsert(
                ['nombre' => $ej['nombre']],
                array_merge($ej, ['gimnasio_id' => $gimnasio_id, 'activo' => true, 'created_at' => $now, 'updated_at' => $now])
            );
        }

        // Obtener IDs de ejercicios por nombre para asociarlos rápido
        $ejIDs = DB::table('entrenamiento.ejercicios')->pluck('id', 'nombre');

        // BUSCAR PLANES CREADOS ANTERIORMENTE
        $planGrupal = DB::table('entrenamiento.planes')->where('nombre', 'Plan Muscular Híbrido (Grupal)')->first();
        $planRM = DB::table('entrenamiento.planes')->where('nombre', 'Plan de Fuerza por RM (Yandry Navarrete)')->first();

        // Limpiar rutinas antiguas en nuevas tablas
        DB::table('entrenamiento.plan_dias')->delete();
        DB::table('entrenamiento.rutinas')->delete();

        // 2. GENERAR RUTINAS PLAN GRUPAL (4 Semanas)
        if ($planGrupal) {
            for ($semana = 1; $semana <= 4; $semana++) {
                // DÍA 1: HÍBRIDO A
                $this->guardarDiaEstructurado($planGrupal->id, $semana, 'LUNES', [
                    'Bloque 1' => [
                        ['ej_id' => $ejIDs['Balón plio colchoneta negra'], 's' => 4, 'r' => '20'],
                        ['ej_id' => $ejIDs['Liga flex. pecho'], 's' => 4, 'r' => '10'],
                    ],
                    'Bloque 2' => [
                        ['ej_id' => $ejIDs['Pecho inclinado a una mano'], 's' => 3, 'r' => '12 x lado'],
                        ['ej_id' => $ejIDs['Vuelos frontales cabo'], 's' => 3, 'r' => '14'],
                    ],
                    'Bloque 3' => [
                        ['ej_id' => $ejIDs['Press pecho'], 's' => 3, 'r' => '14, 12, 10'],
                        ['ej_id' => $ejIDs['Vuelos laterales'], 's' => 3, 'r' => '10'],
                    ],
                    'Finisher' => [
                        ['ej_id' => $ejIDs['Burpees'], 's' => 4, 'r' => '10', 'tr_id' => $ejIDs['Press hombro'], 'tr_r' => '2'],
                        ['ej_id' => $ejIDs['Step flex. ida y vuelta'], 's' => 3, 'r' => 'ida y vuelta'],
                    ],
                ]);

                // DÍA 2: DEPORTIVO A
                $this->guardarDiaEstructurado($planGrupal->id, $semana, 'MARTES', [
                    'Bloque 1' => [
                        ['ej_id' => $ejIDs['Balón plio colchoneta verde'], 's' => 4, 'r' => '20'],
                        ['ej_id' => $ejIDs['Empuje de balón desde piso'], 's' => 4, 'r' => '10'],
                    ],
                    'Bloque 2 (Pesado)' => [
                        ['ej_id' => $ejIDs['Press pecho'], 's' => 3, 'r' => '8, 6, 4'],
                        ['ej_id' => $ejIDs['Liga flex. pecho'], 's' => 1, 'r' => '10'],
                    ],
                    'Bloque 3' => [
                        ['ej_id' => $ejIDs['Remo barra'], 's' => 3, 'r' => '8'],
                    ],
                    'Finisher' => [
                        ['ej_id' => $ejIDs['Burpees'], 's' => 3, 'r' => '6', 'tr_id' => $ejIDs['Dominadas'], 'tr_r' => '2'],
                    ],
                    'Cardio' => [
                        ['ej_id' => $ejIDs['Carreras 10mts'], 's' => 10, 'r' => '1 min'],
                    ],
                ]);

                // DÍA 3: HÍBRIDO B
                $this->guardarDiaEstructurado($planGrupal->id, $semana, 'MIERCOLES', [
                    'Bloque 1' => [
                        ['ej_id' => $ejIDs['Biceps barra'], 's' => 4, 'r' => '14, 12, 10, 8', 'tr_id' => $ejIDs['Tríceps copa'], 'tr_r' => '10'],
                        ['ej_id' => $ejIDs['Biceps concentrado'], 's' => 4, 'r' => '14, 14, 10, 10', 'tr_id' => $ejIDs['Triceps cabo'], 'tr_r' => '10'],
                    ],
                    'Bloque 2' => [
                        ['ej_id' => $ejIDs['Predicador'], 's' => 3, 'r' => '14, 12, fallo'],
                        ['ej_id' => $ejIDs['Cabo desde la cabeza'], 's' => 4, 'r' => '12'],
                    ],
                    'Bloque 3' => [
                        ['ej_id' => $ejIDs['Tijera pasos cortos con Manc.'], 's' => 3, 'r' => 'Ida y Vuelta'],
                    ],
                ]);
                
                // DÍA 4: DEPORTIVO B
                $this->guardarDiaEstructurado($planGrupal->id, $semana, 'JUEVES', [
                    'Bloque 1' => [
                        ['ej_id' => $ejIDs['Pogo jump con una pierna'], 's' => 4, 'r' => '50'],
                        ['ej_id' => $ejIDs['Lateral y pl cajon'], 's' => 4, 'r' => '6 x lado'],
                    ],
                    'Bloque 2' => [
                        ['ej_id' => $ejIDs['Salto lateral tipos pogos'], 's' => 4, 'r' => '15'],
                        ['ej_id' => $ejIDs['Clean squat'], 's' => 4, 'r' => '6'],
                    ],
                    'Bloque 3' => [
                        ['ej_id' => $ejIDs['Habilidad de juego'], 's' => 4, 'r' => '4'],
                    ],
                    'Finisher' => [
                        ['ej_id' => $ejIDs['Kettlebell squat jump'], 's' => 4, 'r' => '8 x lado'],
                        ['ej_id' => $ejIDs['Salto tijera tipo chavo'], 's' => 4, 'r' => '6 x lado'],
                    ],
                ]);
            }
        }

        // 3. GENERAR RUTINAS PLAN RM (4 Semanas)
        if ($planRM) {
            $porcentajes = [1 => '70%', 2 => '75%', 3 => '80%', 4 => '85%']; // Incremento semanal
            
            for ($semana = 1; $semana <= 4; $semana++) {
                $pct = $porcentajes[$semana];
                
                // DÍA 1: Fuerza Pecho + Accesorios
                $this->guardarDiaEstructurado($planRM->id, $semana, 'LUNES', [
                    'RM Principal' => [
                        ['ej_id' => $ejIDs['Press pecho'], 's' => 5, 'r' => '5', 'nota' => "Trabajar al $pct del RM"],
                    ],
                    'Accesorios' => [
                        ['ej_id' => $ejIDs['Pecho inclinado a una mano'], 's' => 3, 'r' => '10 x lado'],
                        ['ej_id' => $ejIDs['Liga flex. pecho'], 's' => 4, 'r' => '15'],
                    ],
                    'Transferencia' => [
                        ['ej_id' => $ejIDs['Burpees'], 's' => 3, 'r' => '8', 'tr_id' => $ejIDs['Press hombro'], 'tr_r' => '4'],
                    ]
                ]);

                // DÍA 2: Fuerza Piernas + Pliometría
                $this->guardarDiaEstructurado($planRM->id, $semana, 'MIERCOLES', [
                    'RM Principal' => [
                        ['ej_id' => $ejIDs['Sentadilla'], 's' => 5, 'r' => '5', 'nota' => "Trabajar al $pct del RM"],
                    ],
                    'Accesorios' => [
                        ['ej_id' => $ejIDs['Salto al cajón desde Sent.'], 's' => 4, 'r' => '8'],
                        ['ej_id' => $ejIDs['Tijera pasos cortos con Manc.'], 's' => 3, 'r' => 'Ida y Vuelta'],
                    ],
                    'Cardio' => [
                        ['ej_id' => $ejIDs['Carreras 10mts'], 's' => 5, 'r' => '1 min'],
                    ]
                ]);

                // DÍA 3: Fuerza Espalda/Brazos
                $this->guardarDiaEstructurado($planRM->id, $semana, 'VIERNES', [
                    'RM Principal' => [
                        ['ej_id' => $ejIDs['Remo barra'], 's' => 4, 'r' => '8', 'nota' => "Trabajar al $pct del RM"],
                    ],
                    'Accesorios' => [
                        ['ej_id' => $ejIDs['Dominadas'], 's' => 4, 'r' => 'Fallo'],
                    ],
                    'Transferencia' => [
                        ['ej_id' => $ejIDs['Biceps barra'], 's' => 4, 'r' => '10', 'tr_id' => $ejIDs['Tríceps copa'], 'tr_r' => '10'],
                    ]
                ]);
            }
        }
    }

    private function guardarDiaEstructurado($plan_id, $semana, $diaNombre, $bloquesData)
    {
        $diaId = DB::table('entrenamiento.plan_dias')->insertGetId([
            'plan_id' => $plan_id,
            'semana' => $semana,
            'dia' => $diaNombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bIndex = 1;
        foreach ($bloquesData as $nombreBloque => $ejercicios) {
            $bloqueId = DB::table('entrenamiento.plan_bloques')->insertGetId([
                'plan_dia_id' => $diaId,
                'nombre' => $nombreBloque,
                'orden' => $bIndex++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($ejercicios as $eIndex => $ej) {
                $ejId = DB::table('entrenamiento.plan_ejercicios')->insertGetId([
                    'plan_bloque_id' => $bloqueId,
                    'ejercicio_id' => $ej['ej_id'],
                    'orden' => $eIndex + 1,
                    'observaciones' => $ej['nota'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                for ($s = 1; $s <= $ej['s']; $s++) {
                    DB::table('entrenamiento.plan_ejercicio_series')->insert([
                        'plan_ejercicio_id' => $ejId,
                        'numero_serie' => $s,
                        'repeticiones' => $ej['r'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if (!empty($ej['tr_id'])) {
                    $transfId = DB::table('entrenamiento.plan_ejercicio_transferencias')->insertGetId([
                        'plan_ejercicio_id' => $ejId,
                        'ejercicio_id' => $ej['tr_id'],
                        'orden' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    for ($s = 1; $s <= $ej['s']; $s++) {
                        DB::table('entrenamiento.plan_transferencia_series')->insert([
                            'transferencia_id' => $transfId,
                            'numero_serie' => $s,
                            'repeticiones' => (string) $ej['tr_r'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }
}
