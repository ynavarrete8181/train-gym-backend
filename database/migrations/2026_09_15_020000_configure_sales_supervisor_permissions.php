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
        'REPORTES-DISPONIBLES',
        'REPORTES-HISTORIAL',
    ];

    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ADMINISTRADOR')
            ->value('id_userrole');

        $supervisorId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'SUPERVISOR DE VENTAS')
            ->value('id_userrole');

        if (! $adminId || ! $supervisorId) {
            return;
        }

        // El rol se reconstruye desde una matriz comercial explícita para evitar
        // heredar permisos técnicos u operativos ajenos a ventas.
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $supervisorId)
            ->delete();

        $funciones = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->whereIn('id_menu', $this->permisos)
            ->where('activo', true)
            ->get();

        foreach ($funciones as $funcion) {
            DB::table('seguridad.cpu_userrolefunction')->insert([
                'id_userrole' => $supervisorId,
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

        // Si ya existen usuarios con este rol, se sincronizan inmediatamente.
        $usuarios = DB::table('seguridad.users')
            ->where('usr_tipo', $supervisorId)
            ->pluck('id');

        foreach ($usuarios as $usuarioId) {
            DB::table('seguridad.cpu_userfunction')
                ->where('id_users', $usuarioId)
                ->delete();

            foreach ($funciones as $funcion) {
                DB::table('seguridad.cpu_userfunction')->insert([
                    'id_users' => $usuarioId,
                    'id_userrole' => $supervisorId,
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
        $supervisorId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'SUPERVISOR DE VENTAS')
            ->value('id_userrole');

        if (! $supervisorId) {
            return;
        }

        DB::table('seguridad.cpu_userfunction')
            ->where('id_userrole', $supervisorId)
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $supervisorId)
            ->whereIn('id_menu', $this->permisos)
            ->delete();
    }
};
