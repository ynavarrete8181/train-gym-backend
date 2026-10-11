<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renombrarSiExiste('sede_aliases', 'sedes_aliases');
        $this->renombrarSiExiste('unidad_aliases', 'unidades_aliases');
        $this->renombrarSiExiste('carrera_area_aliases', 'carreras_areas_aliases');
    }

    public function down(): void
    {
        $this->renombrarSiExiste('carreras_areas_aliases', 'carrera_area_aliases');
        $this->renombrarSiExiste('unidades_aliases', 'unidad_aliases');
        $this->renombrarSiExiste('sedes_aliases', 'sede_aliases');
    }

    private function renombrarSiExiste(string $origen, string $destino): void
    {
        if (Schema::hasTable("institucional.$origen") && ! Schema::hasTable("institucional.$destino")) {
            DB::statement("ALTER TABLE institucional.$origen RENAME TO $destino");
        }
    }
};
