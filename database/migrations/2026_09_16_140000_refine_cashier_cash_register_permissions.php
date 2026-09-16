<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cajeroId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'CAJERO')
            ->value('id_userrole');

        if (! $cajeroId) {
            return;
        }

        // El cajero opera turnos; la configuración permanente de cajas queda
        // para Supervisor de Ventas, Administrador y Superadministrador.
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->where('id_menu', 'VENTAS-CAJAS')
            ->delete();

        DB::table('seguridad.cpu_userfunction')
            ->where('id_userrole', $cajeroId)
            ->where('id_menu', 'VENTAS-CAJAS')
            ->delete();

        $turno = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->where('id_menu', 'VENTAS-TURNOS-CAJA')
            ->first();

        if (! $turno) {
            $referencia = DB::table('seguridad.cpu_userrolefunction')
                ->where('id_userrole', $cajeroId)
                ->where('id_menu', 'VENTAS-VENTAS')
                ->first();

            if ($referencia) {
                DB::table('seguridad.cpu_userrolefunction')->insert([
                    'id_userrole' => $cajeroId,
                    'id_usermenu' => $referencia->id_usermenu,
                    'nombre' => 'Turnos de caja',
                    'icono' => 'fa-solid fa-clock-rotate-left',
                    'accion' => '/ventas/turnos-caja',
                    'id_menu' => 'VENTAS-TURNOS-CAJA',
                    'activo' => true,
                    'orden' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $turno = DB::table('seguridad.cpu_userrolefunction')
                    ->where('id_userrole', $cajeroId)
                    ->where('id_menu', 'VENTAS-TURNOS-CAJA')
                    ->first();
            }
        }

        if ($turno) {
            $usuarios = DB::table('seguridad.users')
                ->where('usr_tipo', $cajeroId)
                ->pluck('id');

            foreach ($usuarios as $usuarioId) {
                if (! DB::table('seguridad.cpu_userfunction')
                    ->where('id_users', $usuarioId)
                    ->where('id_menu', 'VENTAS-TURNOS-CAJA')
                    ->exists()) {
                    DB::table('seguridad.cpu_userfunction')->insert([
                        'id_users' => $usuarioId,
                        'id_userrole' => $cajeroId,
                        'id_usermenu' => $turno->id_usermenu,
                        'nombre' => $turno->nombre,
                        'icono' => $turno->icono,
                        'accion' => $turno->accion,
                        'id_menu' => $turno->id_menu,
                        'activo' => true,
                        'orden' => $turno->orden,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->where('id_menu', 'VENTAS-VENTAS')
            ->update(['orden' => 3, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->where('id_menu', 'VENTAS-PAGOS')
            ->update(['orden' => 4, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->where('id_menu', 'VENTAS-COMPROBANTES')
            ->update(['orden' => 5, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No se restaura VENTAS-CAJAS automáticamente para evitar ampliar
        // privilegios operativos al revertir una migración de seguridad.
    }
};
