<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permisos = [
        'DASHBOARD',
        'GIMNASIO-DEPORTISTAS',
        'GIMNASIO-MEMBRESIAS',
        'GIMNASIO-SERVICIOS',
        'GIMNASIO-HORARIOS',
        'GIMNASIO-RESERVAS-DIA',
    ];

    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ADMINISTRADOR')
            ->value('id_userrole');

        $recepcionistaId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'RECEPCIONISTA')
            ->value('id_userrole');

        if (! $adminId || ! $recepcionistaId) {
            return;
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $recepcionistaId)
            ->delete();

        $funciones = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->whereIn('id_menu', $this->permisos)
            ->where('activo', true)
            ->get();

        foreach ($funciones as $funcion) {
            DB::table('seguridad.cpu_userrolefunction')->insert([
                'id_userrole' => $recepcionistaId,
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
            ->where('usr_tipo', $recepcionistaId)
            ->pluck('id');

        foreach ($usuarios as $usuarioId) {
            DB::table('seguridad.cpu_userfunction')
                ->where('id_users', $usuarioId)
                ->delete();

            foreach ($funciones as $funcion) {
                DB::table('seguridad.cpu_userfunction')->insert([
                    'id_users' => $usuarioId,
                    'id_userrole' => $recepcionistaId,
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
        $recepcionistaId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'RECEPCIONISTA')
            ->value('id_userrole');

        if (! $recepcionistaId) {
            return;
        }

        DB::table('seguridad.cpu_userfunction')
            ->where('id_userrole', $recepcionistaId)
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $recepcionistaId)
            ->whereIn('id_menu', $this->permisos)
            ->delete();
    }
};
