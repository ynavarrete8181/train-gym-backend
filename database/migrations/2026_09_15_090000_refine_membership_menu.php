<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_userrolefunction')
            ->whereIn('id_menu', ['GIMNASIO-PLANES', 'GIMNASIO-MEMBRESIAS'])
            ->whereNotNull('id_usermenu')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'GIMNASIO-PLANES'],
            [
                'clave_pagina' => 'PlanesPage',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'GIMNASIO-MEMBRESIAS'],
            [
                'clave_pagina' => 'MembresiasPage',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->update([
                'id_usermenu' => $menuId,
                'nombre' => 'Planes de membresía',
                'icono' => 'list_alt',
                'orden' => 1,
                'activo' => true,
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'id_usermenu' => $menuId,
                'nombre' => 'Membresías',
                'icono' => 'card_membership',
                'accion' => '/membresias',
                'orden' => 2,
                'activo' => true,
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->update([
                'id_usermenu' => $menuId,
                'nombre' => 'Planes de membresía',
                'icono' => 'list_alt',
                'orden' => 1,
                'activo' => true,
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'id_usermenu' => $menuId,
                'nombre' => 'Membresías',
                'icono' => 'card_membership',
                'accion' => '/membresias',
                'orden' => 2,
                'activo' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'nombre' => 'Asignar membresía',
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'nombre' => 'Asignar membresía',
                'updated_at' => now(),
            ]);
    }
};
