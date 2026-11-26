<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Reporte "Clientes" (padrón con datos y estado) en el menú Reportes, para administración y supervisión. */
return new class extends Migration
{
    private const MENU = 'REPORTES-CLIENTES';

    public function up(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Reportes')->value('id_usermenu');
        if (! $menuId) {
            return;
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => self::MENU],
            ['clave_pagina' => 'ClientesReportePage', 'created_at' => now(), 'updated_at' => now()]
        );

        $funcion = [
            'id_usermenu' => $menuId,
            'nombre' => 'Clientes',
            'accion' => '/reportes/clientes',
            'icono' => 'groups',
            'activo' => true,
            'orden' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'])
            ->where('activo', true)->get(['id_userrole']);
        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol->id_userrole, 'id_menu' => self::MENU], $funcion);
            foreach (DB::table('seguridad.users')->where('usr_tipo', $rol->id_userrole)->get(['id']) as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $usuario->id, 'id_menu' => self::MENU], $funcion + ['id_userrole' => $rol->id_userrole]);
            }
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', self::MENU)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', self::MENU)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', self::MENU)->delete();
    }
};
