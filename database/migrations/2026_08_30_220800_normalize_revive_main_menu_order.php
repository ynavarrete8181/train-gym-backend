<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $orden = [
            'Seguridad' => 1,
            'Estructura operativa' => 2,
            'Operaciones' => 3,
            'Integraciones' => 4,
            'Notificaciones' => 5,
            'Clientes' => 6,
            'Membresías' => 7,
            'Servicios y Agenda' => 8,
            'Entrenamiento' => 9,
            'Inventario' => 10,
            'Ventas' => 11,
            'Acceso' => 12,
            'Resultados' => 13,
            'Comunicaciones' => 14,
            'Reportes' => 15,
        ];

        foreach ($orden as $menu => $posicion) {
            DB::table('seguridad.cpu_usermenu')
                ->where('menu', $menu)
                ->update(['orden' => $posicion, 'activo' => true, 'updated_at' => $now]);
        }

        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Equipo')
            ->update(['activo' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Equipo')
            ->update(['activo' => true, 'updated_at' => Carbon::now()]);
    }
};
