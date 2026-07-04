<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PlanesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // 1. Plan Grupal
        DB::table('entrenamiento.planes')->insert([
            'persona_id' => null,
            'nombre' => 'Plan Muscular Híbrido (Grupal)',
            'objetivo' => 'Mejorar fuerza hipertrófica combinada con resistencia metabólica para grupos grandes.',
            'fecha_inicio' => $now->toDateString(),
            'fecha_fin' => $now->addMonths(3)->toDateString(),
            'estado' => 'ACTIVO',
            'observaciones' => 'Plan diseñado para clases grupales.',
            'tipo' => 'HIBRIDO',
            'alcance' => 'GRUPAL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 2. Plan por RM para Yandry Navarrete (persona_id = 1)
        DB::table('entrenamiento.planes')->insert([
            'persona_id' => 1,
            'nombre' => 'Plan de Fuerza por RM (Yandry Navarrete)',
            'objetivo' => 'Aumento de 1RM en ejercicios básicos (Sentadilla, Banca, Peso Muerto).',
            'fecha_inicio' => $now->toDateString(),
            'fecha_fin' => $now->addMonths(2)->toDateString(),
            'estado' => 'ACTIVO',
            'observaciones' => 'Programa de progresión lineal basado en porcentajes de Repetición Máxima (RM).',
            'tipo' => 'FUERZA',
            'alcance' => 'PERSONAL',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
