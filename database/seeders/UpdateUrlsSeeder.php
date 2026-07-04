<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateUrlsSeeder extends Seeder
{
    public function run(): void
    {
        // Links validados y funcionales
        $videoLinks = [
            'Piernas' => 'https://www.youtube.com/watch?v=gcNh17Ckjgg',
            'Pecho' => 'https://www.youtube.com/watch?v=rT7DgCr-3pg',
            'Espalda' => 'https://www.youtube.com/watch?v=op9kVnSso6Q',
            'Hombros' => 'https://www.youtube.com/watch?v=qEwKCR5JCog',
            'Brazos' => 'https://www.youtube.com/watch?v=sAq_ocpRh_I', // Biceps
            'Deportivo' => 'https://www.youtube.com/watch?v=52r_Ul5k03g', // Pliometría / Saltos
            'Híbrido' => 'https://www.youtube.com/watch?v=Pj2WAEheCSk', // Clean and Jerk
            'Cardio' => 'https://www.youtube.com/watch?v=Rx_UHXk0T1Q', // Balón medicinal / intenso
            'Core' => 'https://www.youtube.com/watch?v=Rx_UHXk0T1Q',
            'Cuerpo Completo' => 'https://www.youtube.com/watch?v=Pj2WAEheCSk',
            'Agilidad' => 'https://www.youtube.com/watch?v=52r_Ul5k03g',
        ];

        $ejercicios = DB::table('entrenamiento.ejercicios')->whereNull('url_recurso')->orWhere('url_recurso', '')->get();

        foreach ($ejercicios as $ej) {
            // Asignar link por grupo muscular o tipo
            $link = $videoLinks[$ej->grupo_muscular] ?? $videoLinks[$ej->tipo_entrenamiento] ?? 'https://www.youtube.com/watch?v=52r_Ul5k03g';
            
            DB::table('entrenamiento.ejercicios')
                ->where('id', $ej->id)
                ->update(['url_recurso' => $link]);
        }
    }
}
