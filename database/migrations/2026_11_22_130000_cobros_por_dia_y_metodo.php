<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Cobros por método" pasa a llamarse "Cobros por día y método" (muestra el subtotal de cada
 * día por método, para cuadrar con la caja y el banco) y el rol Administrador también lo ve.
 */
return new class extends Migration
{
    private const MENU = 'REPORTES-COBROS-METODOS';

    public function up(): void
    {
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)->where('id_menu', self::MENU)->update(['nombre' => 'Cobros por día y método', 'updated_at' => now()]);
        }

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Reportes')->value('id_usermenu');
        $rol = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->where('activo', true)->value('id_userrole');
        if (! $menuId || ! $rol) {
            return;
        }
        $funcion = [
            'id_usermenu' => $menuId,
            'nombre' => 'Cobros por día y método',
            'accion' => '/reportes/cobros-metodo-pago',
            'icono' => 'payments',
            'activo' => true,
            'orden' => 4,
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
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)->where('id_menu', self::MENU)->update(['nombre' => 'Cobros por método']);
        }
    }
};
