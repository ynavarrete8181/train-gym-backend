<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RmRealesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Limpiar registros RM anteriores
        DB::table('entrenamiento.rm_registros')->truncate();

        // 1. Yandry Navarrete - RM Directo Sentadilla
        DB::table('entrenamiento.rm_registros')->insert([
            'persona_id' => 1,
            'ejercicio_id' => 42, // Sentadilla Libre con Barra (Squat)
            'tipo_registro' => 'DIRECTO',
            'peso' => 140.00,
            'repeticiones' => 1,
            'rm_estimado' => 140.00, // 140 * (1 + 1/30) = 144.6 aprox, pero en directo se respeta el RM
            'fecha_registro' => $now->copy()->subDays(2)->toDateString(),
            'fecha_proximo_control' => $now->copy()->addDays(28)->toDateString(),
            'observaciones' => 'Buen bloqueo al final. Subida controlada.',
            'created_at' => $now,
            'updated_at' => $now
        ]);

        // 2. Yandry Navarrete - RM Estimado Press Pecho
        DB::table('entrenamiento.rm_registros')->insert([
            'persona_id' => 1,
            'ejercicio_id' => 9, // Press pecho
            'tipo_registro' => 'ESTIMADO',
            'peso' => 100.00,
            'repeticiones' => 5,
            'rm_estimado' => 116.67, // Fórmula Epley: 100 * (1 + 5/30) = 116.67
            'fecha_registro' => $now->copy()->subDays(4)->toDateString(),
            'fecha_proximo_control' => $now->copy()->addDays(26)->toDateString(),
            'observaciones' => 'Hombros estables. Podía dar una repetición más.',
            'created_at' => $now,
            'updated_at' => $now
        ]);

        // 3. Juan Pérez - RM Estimado Sentadilla Básica
        DB::table('entrenamiento.rm_registros')->insert([
            'persona_id' => 2, // Juan Pérez
            'ejercicio_id' => 1, // Sentadilla
            'tipo_registro' => 'ESTIMADO',
            'peso' => 60.00,
            'repeticiones' => 8,
            'rm_estimado' => 76.00, // Fórmula Epley: 60 * (1 + 8/30) = 76
            'fecha_registro' => $now->copy()->subDays(5)->toDateString(),
            'fecha_proximo_control' => $now->copy()->addDays(25)->toDateString(),
            'observaciones' => 'Buena profundidad, le falta un poco de estabilidad.',
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }
}
