<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODIGO = 'SEGURIDAD-PERMISOS-USUARIOS';

    public function up(): void
    {
        $menu = DB::table('seguridad.cpu_usermenu')->where('menu', 'Administración')->value('id_usermenu');
        if (! $menu) {
            return;
        }

        $funcionesRol = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'SEGURIDAD-USUARIOS')
            ->get();

        foreach ($funcionesRol as $funcion) {
            $orden = ((int) DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrole', $funcion->id_userrole)
                ->where('id_usermenu', $menu)
                ->max('orden')) + 1;

            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $funcion->id_userrole, 'id_menu' => self::CODIGO],
                [
                    'id_usermenu' => $menu,
                    'nombre' => 'Permisos de usuarios',
                    'icono' => 'manage_accounts',
                    'accion' => 'seguridad/permisos-usuarios',
                    'activo' => true,
                    'orden' => $orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $usuarios = DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'SEGURIDAD-USUARIOS')
            ->select('id_users', 'id_userrole')
            ->distinct()
            ->get();

        foreach ($usuarios as $usuario) {
            $orden = ((int) DB::table('seguridad.cpu_userfunction')
                ->where('id_users', $usuario->id_users)
                ->where('id_usermenu', $menu)
                ->max('orden')) + 1;

            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $usuario->id_users, 'id_menu' => self::CODIGO],
                [
                    'id_userrole' => $usuario->id_userrole,
                    'id_usermenu' => $menu,
                    'nombre' => 'Permisos de usuarios',
                    'icono' => 'manage_accounts',
                    'accion' => 'seguridad/permisos-usuarios',
                    'activo' => true,
                    'orden' => $orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => self::CODIGO],
            ['clave_pagina' => 'PermisosUsuariosPage', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', self::CODIGO)->delete();
        DB::table('seguridad.cpu_userfunction')->where('id_menu', self::CODIGO)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', self::CODIGO)->delete();
    }
};
