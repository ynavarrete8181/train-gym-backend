<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La asignación de membresías a un cliente ahora se hace desde la ficha del
 * Cliente (pestaña "Membresía"), no desde una pantalla independiente. Se
 * quita el submenú "Asignar membresía" (código GIMNASIO-MEMBRESIAS) del
 * menú "Membresías", que ahora solo muestra "Planes de membresía".
 *
 * El endpoint /base/gimnasio/membresias y la página MembresiasPage.jsx no
 * se tocan (la ficha del cliente los sigue usando por debajo), solo se
 * retira el enlace del menú lateral.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'GIMNASIO-MEMBRESIAS')->delete();
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'GIMNASIO-MEMBRESIAS')->delete();
    }

    public function down(): void
    {
        $now = Carbon::now();
        $menuMembresias = DB::table('seguridad.cpu_usermenu')->where('menu', 'Membresías')->value('id_usermenu');

        if (! $menuMembresias) {
            return;
        }

        $roles = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->pluck('id_userrole');

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol, 'id_menu' => 'GIMNASIO-MEMBRESIAS'],
                [
                    'id_usermenu' => $menuMembresias,
                    'nombre' => 'Asignar membresía',
                    'icono' => 'card_membership',
                    'accion' => '/membresias',
                    'orden' => 2,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $usuarios = DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->get(['id_users', 'id_userrole']);

        foreach ($usuarios as $u) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $u->id_users, 'id_menu' => 'GIMNASIO-MEMBRESIAS'],
                [
                    'id_userrole' => $u->id_userrole,
                    'id_usermenu' => $menuMembresias,
                    'nombre' => 'Asignar membresía',
                    'icono' => 'card_membership',
                    'accion' => '/membresias',
                    'orden' => 2,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
