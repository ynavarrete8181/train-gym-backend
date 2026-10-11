<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permisos = [
        'DASHBOARD',
        'GIMNASIO-DEPORTISTAS',
        'GIMNASIO-MEMBRESIAS',
        'VENTAS-CAJAS',
        'VENTAS-VENTAS',
        'VENTAS-PAGOS',
        'VENTAS-COMPROBANTES',
    ];

    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ADMINISTRADOR')
            ->value('id_userrole');

        $cajeroId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'CAJERO')
            ->value('id_userrole');

        if (! $adminId || ! $cajeroId) {
            return;
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->delete();

        $funciones = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->whereIn('id_menu', $this->permisos)
            ->where('activo', true)
            ->get();

        foreach ($funciones as $funcion) {
            DB::table('seguridad.cpu_userrolefunction')->insert([
                'id_userrole' => $cajeroId,
                'id_usermenu' => $funcion->id_usermenu,
                'nombre' => $funcion->nombre,
                'icono' => $funcion->icono,
                'accion' => $funcion->accion,
                'id_menu' => $funcion->id_menu,
                'activo' => true,
                'orden' => $funcion->orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $usuarios = DB::table('seguridad.users')
            ->where('usr_tipo', $cajeroId)
            ->pluck('id');

        foreach ($usuarios as $usuarioId) {
            DB::table('seguridad.cpu_userfunction')
                ->where('id_users', $usuarioId)
                ->delete();

            foreach ($funciones as $funcion) {
                DB::table('seguridad.cpu_userfunction')->insert([
                    'id_users' => $usuarioId,
                    'id_userrole' => $cajeroId,
                    'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre,
                    'icono' => $funcion->icono,
                    'accion' => $funcion->accion,
                    'id_menu' => $funcion->id_menu,
                    'activo' => true,
                    'orden' => $funcion->orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $cajeroId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'CAJERO')
            ->value('id_userrole');

        if (! $cajeroId) {
            return;
        }

        DB::table('seguridad.cpu_userfunction')
            ->where('id_userrole', $cajeroId)
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $cajeroId)
            ->whereIn('id_menu', $this->permisos)
            ->delete();
    }
};
