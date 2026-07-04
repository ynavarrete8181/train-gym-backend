<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EjerciciosMasivosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $gimnasio_id = 1; // Gimnasio por defecto

        $ejercicios = [
            // FUERZA / HIPERTROFIA
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Sentadilla Libre con Barra (Squat)',
                'grupo_muscular' => 'Piernas',
                'equipamiento' => 'Barra Libre',
                'instrucciones' => 'Pies al ancho de los hombros, flexiona la cadera y las rodillas bajando como si fueras a sentarte, manteniendo la espalda recta. Activa el core en todo momento.',
                'url_recurso' => 'https://www.youtube.com/watch?v=gcNh17Ckjgg',
                'tipo_entrenamiento' => 'Fuerza',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Press de Banca Plano',
                'grupo_muscular' => 'Pecho',
                'equipamiento' => 'Barra Libre',
                'instrucciones' => 'Acuéstate en el banco, retrae las escápulas y baja la barra hasta tocar el esternón. Empuja con fuerza manteniendo los pies firmes en el suelo.',
                'url_recurso' => 'https://www.youtube.com/watch?v=rT7DgCr-3pg',
                'tipo_entrenamiento' => 'Fuerza',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Peso Muerto Convencional',
                'grupo_muscular' => 'Espalda',
                'equipamiento' => 'Barra Libre',
                'instrucciones' => 'Flexiona cadera y rodillas para agarrar la barra. Saca pecho, contrae glúteos y espalda baja, y levanta el peso extendiendo cadera y rodillas simultáneamente.',
                'url_recurso' => 'https://www.youtube.com/watch?v=op9kVnSso6Q',
                'tipo_entrenamiento' => 'Fuerza',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Dominadas (Pull-ups)',
                'grupo_muscular' => 'Espalda',
                'equipamiento' => 'Peso Corporal',
                'instrucciones' => 'Agarre prono más ancho que los hombros. Tira de tu cuerpo hacia arriba hasta que la barbilla pase la barra, retrayendo las escápulas.',
                'url_recurso' => 'https://www.youtube.com/watch?v=eGo4IYtlcJE',
                'tipo_entrenamiento' => 'Muscular',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Press Militar con Mancuernas',
                'grupo_muscular' => 'Hombros',
                'equipamiento' => 'Mancuernas',
                'instrucciones' => 'Sentado o de pie, empuja las mancuernas desde la altura de los hombros hasta la extensión completa de los brazos. Evita arquear la espalda.',
                'url_recurso' => 'https://www.youtube.com/watch?v=qEwKCR5JCog',
                'tipo_entrenamiento' => 'Muscular',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Remo con Barra',
                'grupo_muscular' => 'Espalda',
                'equipamiento' => 'Barra Libre',
                'instrucciones' => 'Torso inclinado a 45 grados, espalda recta. Tira de la barra hacia el abdomen contrayendo la espalda.',
                'url_recurso' => 'https://www.youtube.com/watch?v=vT2GjY_Umpw',
                'tipo_entrenamiento' => 'Muscular',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Hip Thrust',
                'grupo_muscular' => 'Glúteos',
                'equipamiento' => 'Barra Libre',
                'instrucciones' => 'Espalda alta apoyada en el banco. Empuja la cadera hacia arriba contrayendo fuertemente los glúteos en la parte superior.',
                'url_recurso' => 'https://www.youtube.com/watch?v=xDoeT9A1yxg',
                'tipo_entrenamiento' => 'Muscular',
            ],
            
            // DEPORTIVOS / PLIOMETRÍA
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Salto al Cajón (Box Jump)',
                'grupo_muscular' => 'Piernas',
                'equipamiento' => 'Cajón Pliométrico',
                'instrucciones' => 'Posición atlética, carga energía flexionando caderas y brazos. Salta explosivamente hacia el cajón y aterriza suavemente en posición de media sentadilla.',
                'url_recurso' => 'https://www.youtube.com/watch?v=52r_Ul5k03g',
                'tipo_entrenamiento' => 'Deportivo',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Clean and Jerk (Envión)',
                'grupo_muscular' => 'Cuerpo Completo',
                'equipamiento' => 'Barra Olímpica',
                'instrucciones' => 'Levantamiento explosivo desde el suelo hasta los hombros (clean), seguido de un empuje explosivo por encima de la cabeza (jerk).',
                'url_recurso' => 'https://www.youtube.com/watch?v=Pj2WAEheCSk',
                'tipo_entrenamiento' => 'Híbrido',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Lanzamiento de Balón Medicinal',
                'grupo_muscular' => 'Core',
                'equipamiento' => 'Balón Medicinal',
                'instrucciones' => 'Fuerza rotacional desde la cadera. Lanza el balón contra la pared con explosividad pivotando el pie trasero.',
                'url_recurso' => 'https://www.youtube.com/watch?v=Rx_UHXk0T1Q',
                'tipo_entrenamiento' => 'Deportivo',
            ],
            
            // AISLAMIENTO
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Curl de Bíceps Alterno',
                'grupo_muscular' => 'Brazos',
                'equipamiento' => 'Mancuernas',
                'instrucciones' => 'Flexiona el codo levantando la mancuerna hacia el hombro. Supina la muñeca al subir.',
                'url_recurso' => 'https://www.youtube.com/watch?v=sAq_ocpRh_I',
                'tipo_entrenamiento' => 'Muscular',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Extensión de Tríceps en Polea',
                'grupo_muscular' => 'Brazos',
                'equipamiento' => 'Polea',
                'instrucciones' => 'Codos fijos a los lados del cuerpo. Extiende los brazos completamente hacia abajo contrayendo el tríceps.',
                'url_recurso' => 'https://www.youtube.com/watch?v=2-LAMcpzODU',
                'tipo_entrenamiento' => 'Muscular',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Elevaciones Laterales',
                'grupo_muscular' => 'Hombros',
                'equipamiento' => 'Mancuernas',
                'instrucciones' => 'Levanta los brazos hacia los lados hasta que los codos estén a la altura de los hombros. Mantén una ligera flexión en los codos.',
                'url_recurso' => 'https://www.youtube.com/watch?v=3VcKaXpzqRo',
                'tipo_entrenamiento' => 'Muscular',
            ],
            [
                'gimnasio_id' => $gimnasio_id,
                'nombre' => 'Prensa de Piernas (Leg Press)',
                'grupo_muscular' => 'Piernas',
                'equipamiento' => 'Máquina',
                'instrucciones' => 'Empuja la plataforma controlando la bajada. No bloquees las rodillas al final de la extensión.',
                'url_recurso' => 'https://www.youtube.com/watch?v=IZxyjW7OSvc',
                'tipo_entrenamiento' => 'Muscular',
            ],
        ];

        foreach ($ejercicios as $ejercicio) {
            DB::table('entrenamiento.ejercicios')->updateOrInsert(
                ['nombre' => $ejercicio['nombre']], // Clave única
                array_merge($ejercicio, [
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
