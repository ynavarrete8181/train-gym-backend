<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FichasRealesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // 1. Ficha para Yandry Navarrete
        $yandryFichaId = DB::table('salud.fichas_tecnicas')->insertGetId([
            'persona_id' => 1,
            'fecha_ficha' => $now->toDateString(),
            'actividad_fisica' => 'Alto',
            'objetivo' => 'Aumento de fuerza e hipertrofia muscular. Mejorar RM en básicos.',
            'observaciones' => 'Deportista avanzado. Sin lesiones recientes. Plan enfocado en fuerza e híbrido.',
            'registrado_por' => null,
            'sede_id' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        DB::table('salud.ficha_mediciones')->insert([
            'ficha_tecnica_id' => $yandryFichaId,
            'peso_kg' => 78.50,
            'talla_cm' => 175.00,
            'imc' => round(78.50 / ((175.00/100) * (175.00/100)), 2),
            'cintura_cm' => 82.00,
            'grasa_corporal_pct' => 12.50,
            'masa_magra_kg' => 68.68,
            'created_at' => $now
        ]);

        // 2. Ficha para Juan Pérez
        $juanFichaId = DB::table('salud.fichas_tecnicas')->insertGetId([
            'persona_id' => 2,
            'fecha_ficha' => $now->copy()->subDays(5)->toDateString(),
            'actividad_fisica' => 'Moderado',
            'objetivo' => 'Pérdida de peso y mejora de resistencia cardiovascular.',
            'observaciones' => 'Principiante. Dolor leve en rodilla derecha al correr.',
            'registrado_por' => null,
            'sede_id' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        DB::table('salud.ficha_mediciones')->insert([
            'ficha_tecnica_id' => $juanFichaId,
            'peso_kg' => 92.00,
            'talla_cm' => 170.00,
            'imc' => round(92.00 / ((170.00/100) * (170.00/100)), 2),
            'cintura_cm' => 98.00,
            'grasa_corporal_pct' => 24.00,
            'masa_magra_kg' => 69.92,
            'created_at' => $now
        ]);

        // 3. Ficha para Luis Cliente
        $luisFichaId = DB::table('salud.fichas_tecnicas')->insertGetId([
            'persona_id' => 5,
            'fecha_ficha' => $now->copy()->subDays(2)->toDateString(),
            'actividad_fisica' => 'Leve',
            'objetivo' => 'Mantenimiento y tonificación general.',
            'observaciones' => 'Recuperándose de esguince de tobillo. Evitar saltos de alto impacto.',
            'registrado_por' => null,
            'sede_id' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        DB::table('salud.ficha_mediciones')->insert([
            'ficha_tecnica_id' => $luisFichaId,
            'peso_kg' => 75.00,
            'talla_cm' => 180.00,
            'imc' => round(75.00 / ((180.00/100) * (180.00/100)), 2),
            'cintura_cm' => 80.00,
            'grasa_corporal_pct' => 14.00,
            'masa_magra_kg' => 64.50,
            'created_at' => $now
        ]);
        
        // Limpiamos el registro de prueba "Ana Trainer" (persona_id = 4) si el usuario lo prefiere
        // O lo dejamos. Voy a borrar el antiguo para que se vea mas limpio y real
        $anaFichas = DB::table('salud.fichas_tecnicas')->where('persona_id', 4)->pluck('id');
        DB::table('salud.ficha_mediciones')->whereIn('ficha_tecnica_id', $anaFichas)->delete();
        DB::table('salud.fichas_tecnicas')->where('persona_id', 4)->delete();
    }
}
