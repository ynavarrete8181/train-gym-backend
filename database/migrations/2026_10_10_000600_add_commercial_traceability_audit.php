<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODIGO = 'AUDITORIA-TRAZABILIDAD-COMERCIAL';

    private array $roles = [
        'SUPERADMINISTRADOR',
        'ADMINISTRADOR',
        'SUPERVISOR DE VENTAS',
    ];

    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Auditoría')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => self::CODIGO],
            [
                'clave_pagina' => 'TrazabilidadComercialPage',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', $this->roles)
            ->where('activo', true)
            ->get(['id_userrole']);

        $roleIds = $roles->pluck('id_userrole');

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', self::CODIGO)
            ->when($roleIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id_userrole', $roleIds))
            ->delete();

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', self::CODIGO)
            ->when($roleIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id_userrole', $roleIds))
            ->delete();

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_usermenu' => $menuId,
                    'id_menu' => self::CODIGO,
                    'accion' => '/auditoria-trazabilidad-comercial',
                ],
                [
                    'nombre' => 'Trazabilidad comercial',
                    'icono' => 'manage_history',
                    'activo' => true,
                    'orden' => 5,
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
                        'accion' => '/auditoria-trazabilidad-comercial',
                    ],
                    [
                        'nombre' => 'Trazabilidad comercial',
                        'icono' => 'manage_history',
                        'activo' => true,
                        'orden' => 5,
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
    }
};
