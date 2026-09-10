<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        // 1. Roles
        $roles = [
            ['role' => 'RECEPCIONISTA', 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'ENTRENADOR', 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'CAJERO', 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'DEPORTISTA', 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
        ];
        
        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrole')->insertOrIgnore($rol);
        }

        // 2. Main Menu
        $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
            'menu' => 'Operaciones',
            'icono' => 'fitness_center',
            'activo' => true,
            'orden' => 2,
            'created_at' => $now,
            'updated_at' => $now
        ], 'id_usermenu');

        // 3. Submenus / Pages
        $submenus = [
            ['id_menu' => 'GIMNASIO-DEPORTISTAS', 'nombre' => 'Deportistas', 'accion' => '/deportistas', 'clave_pagina' => 'DeportistasPage', 'icono' => 'groups'],
            ['id_menu' => 'GIMNASIO-PLANES', 'nombre' => 'Planes', 'accion' => '/planes', 'clave_pagina' => 'PlanesPage', 'icono' => 'list_alt'],
            ['id_menu' => 'GIMNASIO-MEMBRESIAS', 'nombre' => 'Membresias', 'accion' => '/membresias', 'clave_pagina' => 'MembresiasPage', 'icono' => 'card_membership'],
        ];

        $adminRoleId = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->value('id_userrole');
        $recepcionistaRoleId = DB::table('seguridad.cpu_userrole')->where('role', 'RECEPCIONISTA')->value('id_userrole');

        $orden = 1;
        foreach ($submenus as $sub) {
            // Register page
            DB::table('seguridad.cpu_pagina_sistema')->insertOrIgnore([
                'id_menu' => $sub['id_menu'],
                'clave_pagina' => $sub['clave_pagina'],
                'created_at' => $now,
                'updated_at' => $now
            ]);

            // Assign to ADMIN
            if ($adminRoleId) {
                DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                    'id_userrole' => $adminRoleId,
                    'id_usermenu' => $menuId,
                    'nombre' => $sub['nombre'],
                    'icono' => $sub['icono'],
                    'accion' => $sub['accion'],
                    'id_menu' => $sub['id_menu'],
                    'activo' => true,
                    'orden' => $orden,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
            
            // Assign to RECEPCIONISTA (Clients and Memberships, maybe Plans view)
            if ($recepcionistaRoleId) {
                DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                    'id_userrole' => $recepcionistaRoleId,
                    'id_usermenu' => $menuId,
                    'nombre' => $sub['nombre'],
                    'icono' => $sub['icono'],
                    'accion' => $sub['accion'],
                    'id_menu' => $sub['id_menu'],
                    'activo' => true,
                    'orden' => $orden,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
            $orden++;
        }
    }

    public function down(): void
    {
        $menus = ['GIMNASIO-DEPORTISTAS', 'GIMNASIO-PLANES', 'GIMNASIO-MEMBRESIAS'];
        
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $menus)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $menus)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $menus)->delete();
        
        DB::table('seguridad.cpu_usermenu')->where('menu', 'Operaciones')->delete();
        
        DB::table('seguridad.cpu_userrole')->whereIn('role', ['RECEPCIONISTA', 'ENTRENADOR', 'CAJERO', 'DEPORTISTA'])->delete();
    }
};
