<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODIGO = 'DASHBOARD-EJECUTIVO';

    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Dashboard')
            ->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Dashboard',
                'icono' => 'space_dashboard',
                'activo' => true,
                'orden' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'id_usermenu');
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => self::CODIGO],
            [
                'clave_pagina' => 'DashboardEjecutivoPage',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'])
            ->where('activo', true)
            ->get(['id_userrole']);

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_usermenu' => $menuId,
                    'id_menu' => self::CODIGO,
                    'accion' => '/dashboard-ejecutivo',
                ],
                [
                    'nombre' => 'Resumen ejecutivo',
                    'icono' => 'insights',
                    'activo' => true,
                    'orden' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            foreach (DB::table('seguridad.users')->where('usr_tipo', $rol->id_userrole)->get(['id']) as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuario->id,
                        'id_userrole' => $rol->id_userrole,
                        'id_usermenu' => $menuId,
                        'id_menu' => self::CODIGO,
                        'accion' => '/dashboard-ejecutivo',
                    ],
                    [
                        'nombre' => 'Resumen ejecutivo',
                        'icono' => 'insights',
                        'activo' => true,
                        'orden' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', self::CODIGO)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', self::CODIGO)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', self::CODIGO)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Dashboard')->value('id_usermenu');

        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }
    }
};
