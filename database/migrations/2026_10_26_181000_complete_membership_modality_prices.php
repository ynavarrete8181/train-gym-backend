<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sedes = DB::table('institucional.sedes')->where('activo', true)->pluck('id_sede');
        $modalidades = DB::table('membresias.plan_modalidades')->where('activo', true)->get();

        foreach ($modalidades as $modalidad) {
            foreach ($sedes as $sedeId) {
                DB::table('membresias.plan_modalidad_precios_sede')->insertOrIgnore([
                    'modalidad_id' => $modalidad->id,
                    'sede_id' => $sedeId,
                    'precio' => $modalidad->precio_base,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Los precios pueden haber sido ajustados después de la carga inicial;
        // no se eliminan para evitar pérdida de configuración comercial.
    }
};
