<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** El rol Administrador también ve el Resumen comercial (antes solo Superadministrador y Supervisor de ventas). */
return new class extends Migration
{
    private const MENU = 'REPORTES-COMERCIAL-RESUMEN';

    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Reportes')->value('id_usermenu');
        $rol = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->where('activo', true)->value('id_userrole');
        if (! $menuId || ! $rol) {
            return;
        }
        $funcion = [
            'id_usermenu' => $menuId,
            'nombre' => 'Resumen comercial',
            'accion' => '/reportes/resumen-comercial',
            'icono' => 'monitoring',
            'activo' => true,
            'orden' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(['id_userrole' => $rol, 'id_menu' => self::MENU], $funcion);
        foreach (DB::table('seguridad.users')->where('usr_tipo', $rol)->pluck('id') as $usuario) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $usuario, 'id_menu' => self::MENU], $funcion + ['id_userrole' => $rol]);
        }
    }

    public function down(): void
    {
        $rol = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->value('id_userrole');
        DB::table('seguridad.cpu_userrolefunction')->where('id_userrole', $rol)->where('id_menu', self::MENU)->delete();
        DB::table('seguridad.cpu_userfunction')->where('id_userrole', $rol)->where('id_menu', self::MENU)->delete();
    }
};
