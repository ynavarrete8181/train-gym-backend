<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ADMINISTRADOR')
            ->value('id_userrole');

        if (! $adminId) {
            return;
        }

        $base = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->where('activo', true)
            ->first();

        if (! $base) {
            return;
        }

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['CAJERO', 'SUPERVISOR DE VENTAS'])
            ->pluck('id_userrole');

        foreach ($roles as $rolId) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rolId,
                    'id_usermenu' => $base->id_usermenu,
                    'id_menu' => $base->id_menu,
                    'accion' => $base->accion,
                ],
                [
                    'nombre' => $base->nombre,
                    'icono' => $base->icono,
                    'activo' => true,
                    'orden' => $base->orden,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $usuarios = DB::table('seguridad.users')
                ->where('usr_tipo', $rolId)
                ->pluck('id');

            foreach ($usuarios as $usuarioId) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuarioId,
                        'id_userrole' => $rolId,
                        'id_usermenu' => $base->id_usermenu,
                        'id_menu' => $base->id_menu,
                        'accion' => $base->accion,
                    ],
                    [
                        'nombre' => $base->nombre,
                        'icono' => $base->icono,
                        'activo' => true,
                        'orden' => $base->orden,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['CAJERO', 'SUPERVISOR DE VENTAS'])
            ->pluck('id_userrole');

        DB::table('seguridad.cpu_userfunction')
            ->whereIn('id_userrole', $roles)
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->whereIn('id_userrole', $roles)
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->delete();
    }
};
