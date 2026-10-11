<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->update([
                'icono' => 'schedule',
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->update([
                'icono' => 'schedule',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->update([
                'icono' => 'fa-solid fa-clock-rotate-left',
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->update([
                'icono' => 'fa-solid fa-clock-rotate-left',
                'updated_at' => now(),
            ]);
    }
};
