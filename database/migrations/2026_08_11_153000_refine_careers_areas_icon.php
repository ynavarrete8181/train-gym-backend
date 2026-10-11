<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'INSTITUCIONAL-CARRERAS-AREAS')
            ->update(['icono' => 'category', 'updated_at' => now()]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'INSTITUCIONAL-CARRERAS-AREAS')
            ->update(['icono' => 'category', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'INSTITUCIONAL-CARRERAS-AREAS')
            ->update(['icono' => 'school', 'updated_at' => now()]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'INSTITUCIONAL-CARRERAS-AREAS')
            ->update(['icono' => 'school', 'updated_at' => now()]);
    }
};
