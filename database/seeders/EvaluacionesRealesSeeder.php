<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EvaluacionesRealesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Limpiar evaluaciones anteriores
        DB::table('entrenamiento.evaluaciones')->truncate();

        // 1. Evaluación para Yandry Navarrete (Deportiva / Funcional)
        DB::table('entrenamiento.evaluaciones')->insert([
            'persona_id' => 1, // Yandry
            'tipo_evaluacion' => 'DEPORTIVA',
            'fecha_evaluacion' => $now->copy()->subDays(2)->toDateString(),
            'resultado_resumen' => 'Excelente progreso en RM. Sentadilla: 140kg, Peso Muerto: 160kg. Buena explosividad en saltos pliométricos.',
            'observaciones' => 'Mantiene buena técnica en cargas altas (85%+). Se recomienda seguir enfocado en trabajo híbrido para no perder agilidad.',
            'nivel_resultado' => 'EXCELENTE',
            'fecha_proxima_evaluacion' => $now->copy()->addDays(28)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now
        ]);

        // 2. Evaluación para Juan Pérez (Corporal / Inicial)
        DB::table('entrenamiento.evaluaciones')->insert([
            'persona_id' => 2, // Juan
            'tipo_evaluacion' => 'FUNCIONAL',
            'fecha_evaluacion' => $now->copy()->subDays(5)->toDateString(),
            'resultado_resumen' => 'Rango de movimiento limitado en caderas. Dificultad para romper el paralelo en sentadilla libre.',
            'observaciones' => 'Asignar rutinas de movilidad articular antes del entrenamiento. Rodilla derecha estable pero requiere fortalecimiento de glúteo medio.',
            'nivel_resultado' => 'BAJO',
            'fecha_proxima_evaluacion' => $now->copy()->addDays(15)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now
        ]);

        // 3. Evaluación para Luis Cliente (Rehabilitación / Mejora)
        DB::table('entrenamiento.evaluaciones')->insert([
            'persona_id' => 5, // Luis
            'tipo_evaluacion' => 'REHABILITACION',
            'fecha_evaluacion' => $now->copy()->subDays(1)->toDateString(),
            'resultado_resumen' => 'El esguince de tobillo ha sanado en un 90%. Ya soporta cargas unilaterales sin dolor agudo.',
            'observaciones' => 'Excelente mejora. Ya puede transicionar de máquinas a pesos libres de forma progresiva. Evitar rebotes agresivos.',
            'nivel_resultado' => 'MEJORO_TECNICA',
            'fecha_proxima_evaluacion' => $now->copy()->addDays(30)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }
}
