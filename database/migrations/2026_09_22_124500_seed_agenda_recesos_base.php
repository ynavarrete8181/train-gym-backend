<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $recesos = [
            [
                'nombre' => 'Desayuno',
                'tipo' => 'DESAYUNO',
                'hora_inicio' => '10:00',
                'hora_fin' => '10:20',
                'descripcion' => 'Pausa de desayuno.',
            ],
            [
                'nombre' => 'Almuerzo',
                'tipo' => 'ALMUERZO',
                'hora_inicio' => '13:00',
                'hora_fin' => '14:00',
                'descripcion' => 'Pausa de almuerzo.',
            ],
            [
                'nombre' => 'Merienda',
                'tipo' => 'MERIENDA',
                'hora_inicio' => '17:00',
                'hora_fin' => '17:15',
                'descripcion' => 'Pausa de merienda.',
            ],
        ];

        foreach ($recesos as $receso) {
            DB::table('gimnasio.recesos')->updateOrInsert(
                ['nombre' => $receso['nombre']],
                [
                    ...$receso,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('gimnasio.recesos')
            ->whereIn('nombre', ['Desayuno', 'Almuerzo', 'Merienda'])
            ->delete();
    }
};
