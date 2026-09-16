<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gimnasio.planes')
            ->whereIn('nombre', [
                'Deportivo',
                'Fortalecimiento / Rehabilitación',
                'Funcional',
                'Híbrido',
                'Musculación',
            ])
            ->update([
                'tipo_duracion' => 'MESES',
                'duracion' => 1,
                'updated_at' => now(),
            ]);

        DB::table('gimnasio.planes')
            ->whereIn('nombre', [
                'Pase Diario',
                'Pase Diario Familia',
            ])
            ->update([
                'tipo_duracion' => 'DIAS',
                'duracion' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('gimnasio.planes')
            ->whereIn('nombre', [
                'Deportivo',
                'Fortalecimiento / Rehabilitación',
                'Funcional',
                'Híbrido',
                'Musculación',
            ])
            ->update([
                'tipo_duracion' => 'DIAS',
                'duracion' => 30,
                'updated_at' => now(),
            ]);
    }
};
