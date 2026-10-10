<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODIGO = 'VENTAS-REPORTES';

    private array $rolesPermitidos = [
        'SUPERADMINISTRADOR',
        'ADMINISTRADOR',
        'SUPERVISOR DE VENTAS',
    ];

    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Ventas')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => self::CODIGO],
            [
                'clave_pagina' => 'ReportesComercialesPage',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $rolesPermitidos = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', $this->rolesPermitidos)
            ->where('activo', true)
            ->get(['id_userrole']);

        $rolesIds = $rolesPermitidos->pluck('id_userrole');

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', self::CODIGO)
            ->when($rolesIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id_userrole', $rolesIds))
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', self::CODIGO)
            ->when($rolesIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id_userrole', $rolesIds))
            ->delete();

        foreach ($rolesPermitidos as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_usermenu' => $menuId,
                    'id_menu' => self::CODIGO,
                    'accion' => '/reportes-comerciales',
                ],
                [
                    'nombre' => 'Reportes comerciales',
                    'icono' => 'monitoring',
                    'activo' => true,
                    'orden' => 6,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $usuarios = DB::table('seguridad.users')
                ->where('usr_tipo', $rol->id_userrole)
                ->get(['id']);

            foreach ($usuarios as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuario->id,
                        'id_userrole' => $rol->id_userrole,
                        'id_usermenu' => $menuId,
                        'id_menu' => self::CODIGO,
                        'accion' => '/reportes-comerciales',
                    ],
                    [
                        'nombre' => 'Reportes comerciales',
                        'icono' => 'monitoring',
                        'activo' => true,
                        'orden' => 6,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', self::CODIGO)
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', self::CODIGO)
            ->delete();

        DB::table('seguridad.cpu_pagina_sistema')
            ->where('id_menu', self::CODIGO)
            ->delete();
    }
};
