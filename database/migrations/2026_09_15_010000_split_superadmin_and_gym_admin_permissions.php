<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permisosTecnicos = [
        'SEGURIDAD-MENUS',
        'SEGURIDAD-SUBMENUS',
        'SEGURIDAD-ROLES',
        'SEGURIDAD-PAGINAS',
        'SEGURIDAD-PERMISOS-USUARIOS',
        'INTEGRACIONES-CONFIG',
        'INSTITUCIONAL-UNIDADES',
        'INSTITUCIONAL-CAMPOS-AMPLIOS',
        'INSTITUCIONAL-CARRERAS-AREAS',
    ];

    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->value('id_userrole');
        $superadminId = DB::table('seguridad.cpu_userrole')->where('role', 'SUPERADMINISTRADOR')->value('id_userrole');

        if (! $adminId || ! $superadminId) {
            return;
        }

        $funcionesAdmin = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->get();

        foreach ($funcionesAdmin as $funcion) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $superadminId,
                    'id_menu' => $funcion->id_menu,
                ],
                [
                    'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre,
                    'icono' => $funcion->icono,
                    'accion' => $funcion->accion,
                    'orden' => $funcion->orden,
                    'activo' => $funcion->activo,
                    'created_at' => $funcion->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        }

        // La cuenta bootstrap conserva el control técnico global del sistema.
        DB::table('seguridad.users')
            ->whereRaw('LOWER(email) = ?', ['admin@revive.local'])
            ->update(['usr_tipo' => $superadminId, 'updated_at' => now()]);

        $usuariosSuperadmin = DB::table('seguridad.users')
            ->where('usr_tipo', $superadminId)
            ->pluck('id');

        foreach ($usuariosSuperadmin as $usuarioId) {
            DB::table('seguridad.cpu_userfunction')->where('id_users', $usuarioId)->delete();
            foreach (DB::table('seguridad.cpu_userrolefunction')->where('id_userrole', $superadminId)->where('activo', true)->get() as $funcion) {
                DB::table('seguridad.cpu_userfunction')->insert([
                    'id_users' => $usuarioId,
                    'id_userrole' => $superadminId,
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

        // ADMINISTRADOR queda orientado a la operación del gimnasio, no a la plataforma.
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->whereIn('id_menu', $this->permisosTecnicos)
            ->delete();

        $usuariosAdmin = DB::table('seguridad.users')
            ->where('usr_tipo', $adminId)
            ->pluck('id');

        DB::table('seguridad.cpu_userfunction')
            ->whereIn('id_users', $usuariosAdmin)
            ->whereIn('id_menu', $this->permisosTecnicos)
            ->delete();
    }

    public function down(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->value('id_userrole');
        $superadminId = DB::table('seguridad.cpu_userrole')->where('role', 'SUPERADMINISTRADOR')->value('id_userrole');

        if (! $adminId || ! $superadminId) {
            return;
        }

        foreach (DB::table('seguridad.cpu_userrolefunction')->where('id_userrole', $superadminId)->whereIn('id_menu', $this->permisosTecnicos)->get() as $funcion) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $adminId, 'id_menu' => $funcion->id_menu],
                [
                    'id_usermenu' => $funcion->id_usermenu,
                    'nombre' => $funcion->nombre,
                    'icono' => $funcion->icono,
                    'accion' => $funcion->accion,
                    'orden' => $funcion->orden,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
};
