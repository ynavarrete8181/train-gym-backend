<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Equipo')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Equipo',
                'icono' => 'groups',
                'activo' => true,
                'orden' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        } else {
            DB::table('seguridad.cpu_usermenu')
                ->where('id_usermenu', $menuId)
                ->update(['activo' => true, 'updated_at' => $now]);
        }

        $codigo = 'GIMNASIO-ENTRENADORES';
        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => $codigo],
            ['clave_pagina' => 'EntrenadoresPage', 'updated_at' => $now]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR'])
            ->where('activo', true)
            ->pluck('id_userrole');

        foreach ($roles as $rolId) {
            $datos = [
                'id_usermenu' => $menuId,
                'nombre' => 'Entrenadores',
                'icono' => 'groups',
                'accion' => '/entrenadores',
                'activo' => true,
                'orden' => 1,
                'updated_at' => $now,
            ];

            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rolId, 'id_menu' => $codigo],
                $datos + ['created_at' => $now]
            );

            $usuarios = DB::table('seguridad.users')->where('usr_tipo', $rolId)->pluck('id');
            foreach ($usuarios as $usuarioId) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $usuarioId, 'id_menu' => $codigo],
                    $datos + ['id_userrole' => $rolId, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        // No se eliminan asignaciones administradas por usuarios al revertir código.
    }
};
