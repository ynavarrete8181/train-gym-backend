<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tarifas = [
            'Pase Diario' => [
                'Revive Home' => 2.00,
                'Revive Xpadel' => 3.00,
            ],
            'Pase Diario Familia' => [
                'Revive Home' => 1.50,
                'Revive Xpadel' => 2.00,
            ],
        ];

        foreach ($tarifas as $planNombre => $sedes) {
            $planId = DB::table('membresias.planes')->where('nombre', $planNombre)->value('id');
            if (! $planId) {
                continue;
            }

            foreach ($sedes as $sedeNombre => $precio) {
                $sedeId = DB::table('institucional.sedes')->where('nombre', $sedeNombre)->value('id_sede');
                if (! $sedeId) {
                    continue;
                }

                // Completar tarifas faltantes sin reemplazar valores comerciales
                // que ya fueron configurados por el administrador.
                DB::table('membresias.plan_precios_sede')->insertOrIgnore([
                    'plan_id' => $planId,
                    'sede_id' => $sedeId,
                    'precio' => $precio,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Son tarifas operativas conocidas; no se revierten a valores uniformes.
    }
};
