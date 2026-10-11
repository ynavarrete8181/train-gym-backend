<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Estructura operativa')
            ->update(['icono' => 'account_tree', 'updated_at' => now()]);

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'INSTITUCIONAL-SEDES')
            ->update(['icono' => 'location_on', 'updated_at' => now()]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'INSTITUCIONAL-SEDES')
            ->update(['icono' => 'location_on', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Estructura operativa')
            ->update(['icono' => 'account_balance', 'updated_at' => now()]);

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'INSTITUCIONAL-SEDES')
            ->update(['icono' => 'location_city', 'updated_at' => now()]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'INSTITUCIONAL-SEDES')
            ->update(['icono' => 'location_city', 'updated_at' => now()]);
    }
};
