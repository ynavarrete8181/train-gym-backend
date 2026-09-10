<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $menuMembresias = DB::table('seguridad.cpu_usermenu')->where('menu', 'Membresías')->value('id_usermenu');

        if (! $menuMembresias) {
            $menuMembresias = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Membresías',
                'icono' => 'card_membership',
                'activo' => true,
                'orden' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'GIMNASIO-PLANES' => ['nombre' => 'Planes de membresía', 'accion' => '/planes', 'icono' => 'list_alt', 'orden' => 1],
            'GIMNASIO-MEMBRESIAS' => ['nombre' => 'Asignar membresía', 'accion' => '/membresias', 'icono' => 'card_membership', 'orden' => 2],
        ];

        foreach ($funciones as $codigo => $datos) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)
                    ->where('id_menu', $codigo)
                    ->update([
                        'id_usermenu' => $menuMembresias,
                        'nombre' => $datos['nombre'],
                        'icono' => $datos['icono'],
                        'accion' => $datos['accion'],
                        'orden' => $datos['orden'],
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    public function down(): void
    {
        $menuOperaciones = DB::table('seguridad.cpu_usermenu')->where('menu', 'Operaciones')->value('id_usermenu');

        if ($menuOperaciones) {
            $funciones = [
                'GIMNASIO-PLANES' => ['nombre' => 'Planes', 'accion' => '/planes', 'icono' => 'list_alt', 'orden' => 2],
                'GIMNASIO-MEMBRESIAS' => ['nombre' => 'Membresias', 'accion' => '/membresias', 'icono' => 'card_membership', 'orden' => 3],
            ];

            foreach ($funciones as $codigo => $datos) {
                foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                    DB::table($tabla)
                        ->where('id_menu', $codigo)
                        ->update([
                            'id_usermenu' => $menuOperaciones,
                            'nombre' => $datos['nombre'],
                            'icono' => $datos['icono'],
                            'accion' => $datos['accion'],
                            'orden' => $datos['orden'],
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        $menuMembresias = DB::table('seguridad.cpu_usermenu')->where('menu', 'Membresías')->value('id_usermenu');

        if ($menuMembresias && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuMembresias)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuMembresias)->delete();
        }
    }
};
