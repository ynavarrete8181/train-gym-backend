<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $codigos = [
        'REPORTES-COMERCIAL-RESUMEN',
        'REPORTES-CARTERA-VENCIDA',
        'REPORTES-COBROS-METODOS',
    ];

    private array $roles = [
        'SUPERADMINISTRADOR',
        'ADMINISTRADOR',
        'SUPERVISOR DE VENTAS',
    ];

    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Reportes')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Reportes',
                'icono' => 'dashboard',
                'activo' => true,
                'orden' => 13,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'id_usermenu');
        }

        $configuracion = [
            'REPORTES-COMERCIAL-RESUMEN' => [
                'nombre' => 'Resumen comercial',
                'accion' => '/reportes/resumen-comercial',
                'pagina' => 'ResumenComercialPage',
                'icono' => 'monitoring',
                'orden' => 3,
            ],
            'REPORTES-CARTERA-VENCIDA' => [
                'nombre' => 'Cartera vencida',
                'accion' => '/reportes/cartera-vencida',
                'pagina' => 'CarteraVencidaReportePage',
                'icono' => 'account_balance_wallet',
                'orden' => 4,
            ],
            'REPORTES-COBROS-METODOS' => [
                'nombre' => 'Cobros por método',
                'accion' => '/reportes/cobros-metodo-pago',
                'pagina' => 'CobrosMetodoPagoPage',
                'icono' => 'payments',
                'orden' => 5,
            ],
        ];

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', $this->roles)
            ->where('activo', true)
            ->get(['id_userrole']);

        $rolesIds = $roles->pluck('id_userrole');

        foreach ($configuracion as $codigo => $config) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                [
                    'clave_pagina' => $config['pagina'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('seguridad.cpu_userfunction')
                ->where('id_menu', $codigo)
                ->when($rolesIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id_userrole', $rolesIds))
                ->delete();

            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_menu', $codigo)
                ->when($rolesIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id_userrole', $rolesIds))
                ->delete();

            foreach ($roles as $rol) {
                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    [
                        'id_userrole' => $rol->id_userrole,
                        'id_usermenu' => $menuId,
                        'id_menu' => $codigo,
                        'accion' => $config['accion'],
                    ],
                    [
                        'nombre' => $config['nombre'],
                        'icono' => $config['icono'],
                        'activo' => true,
                        'orden' => $config['orden'],
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
                            'id_menu' => $codigo,
                            'accion' => $config['accion'],
                        ],
                        [
                            'nombre' => $config['nombre'],
                            'icono' => $config['icono'],
                            'activo' => true,
                            'orden' => $config['orden'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }

        // Retira el acceso anterior dentro de Ventas para evitar duplicidad.
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'VENTAS-REPORTES')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'VENTAS-REPORTES')->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', 'VENTAS-REPORTES')->delete();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();
    }
};
