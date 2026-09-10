<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $menuClientes = DB::table('seguridad.cpu_usermenu')->where('menu', 'Clientes')->value('id_usermenu');

        if (! $menuClientes) {
            $menuClientes = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Clientes',
                'icono' => 'groups',
                'activo' => true,
                'orden' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-DEPORTISTAS')
                ->update([
                    'id_usermenu' => $menuClientes,
                    'nombre' => 'Clientes',
                    'icono' => 'groups',
                    'accion' => '/clientes',
                    'orden' => 1,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $menuOperaciones = DB::table('seguridad.cpu_usermenu')->where('menu', 'Operaciones')->value('id_usermenu');

        if ($menuOperaciones) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)
                    ->where('id_menu', 'GIMNASIO-DEPORTISTAS')
                    ->update([
                        'id_usermenu' => $menuOperaciones,
                        'nombre' => 'Deportistas',
                        'icono' => 'groups',
                        'accion' => '/deportistas',
                        'orden' => 1,
                        'updated_at' => now(),
                    ]);
            }
        }

        $menuClientes = DB::table('seguridad.cpu_usermenu')->where('menu', 'Clientes')->value('id_usermenu');

        if ($menuClientes && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuClientes)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuClientes)->delete();
        }
    }
};
