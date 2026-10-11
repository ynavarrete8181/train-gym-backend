<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $matriz = [
            'VENTAS-CAJAS' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR'],
            'VENTAS-VENTAS' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS', 'CAJERO'],
            'VENTAS-PAGOS' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS', 'CAJERO'],
            'VENTAS-COMPROBANTES' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS', 'CAJERO'],
            'VENTAS-TURNOS-CAJA' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS', 'CAJERO'],
        ];

        $adminId = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->value('id_userrole');

        foreach ($matriz as $codigo => $rolesPermitidos) {
            $base = DB::table('seguridad.cpu_userrolefunction')
                ->where('id_menu', $codigo)
                ->when($adminId, fn ($q) => $q->orderByRaw('CASE WHEN id_userrole = ? THEN 0 ELSE 1 END', [$adminId]))
                ->where('activo', true)
                ->first();

            if (! $base) {
                continue;
            }

            $rolesIds = DB::table('seguridad.cpu_userrole')
                ->whereIn('role', $rolesPermitidos)
                ->where('activo', true)
                ->pluck('id_userrole');

            DB::table('seguridad.cpu_userfunction')
                ->where('id_menu', $codigo)
                ->whereNotIn('id_userrole', $rolesIds)
                ->delete();

            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_menu', $codigo)
                ->whereNotIn('id_userrole', $rolesIds)
                ->delete();

            foreach ($rolesIds as $rolId) {
                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    [
                        'id_userrole' => $rolId,
                        'id_usermenu' => $base->id_usermenu,
                        'id_menu' => $codigo,
                        'accion' => $base->accion,
                    ],
                    [
                        'nombre' => $codigo === 'VENTAS-PAGOS' ? 'Cobros' : $base->nombre,
                        'icono' => $base->icono,
                        'activo' => true,
                        'orden' => $base->orden,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                foreach (DB::table('seguridad.users')->where('usr_tipo', $rolId)->get(['id', 'usr_tipo']) as $usuario) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                        [
                            'id_users' => $usuario->id,
                            'id_userrole' => $rolId,
                            'id_usermenu' => $base->id_usermenu,
                            'id_menu' => $codigo,
                            'accion' => $base->accion,
                        ],
                        [
                            'nombre' => $codigo === 'VENTAS-PAGOS' ? 'Cobros' : $base->nombre,
                            'icono' => $base->icono,
                            'activo' => true,
                            'orden' => $base->orden,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // No se revierte automáticamente la matriz de seguridad para evitar
        // reactivar permisos comerciales obsoletos de usuarios específicos.
    }
};
