<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $menu = DB::table('seguridad.cpu_usermenu')->where('menu', 'Administración')->value('id_usermenu');
        if (! $menu) {
            return;
        }
        $defs = [['Configuración de APIs', 'api', 'integraciones', 'INTEGRACIONES-CONFIG', 6], ['Plantillas de correo', 'drafts', 'notificaciones/plantillas', 'NOTIFICACIONES-PLANTILLAS', 7]];
        $roles = DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'SEGURIDAD-USUARIOS')->pluck('id_userrole')->unique();
        foreach ($roles as $rol) {
            foreach ($defs as [$nombre,$icono,$accion,$codigo,$orden]) {
                DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore(['id_userrole' => $rol, 'id_usermenu' => $menu, 'nombre' => $nombre, 'icono' => $icono, 'accion' => $accion, 'id_menu' => $codigo, 'activo' => true, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (DB::table('seguridad.cpu_userfunction')->where('id_menu', 'SEGURIDAD-USUARIOS')->get() as $f) {
            foreach ($defs as [$nombre,$icono,$accion,$codigo,$orden]) {
                DB::table('seguridad.cpu_userfunction')->insertOrIgnore(['id_users' => $f->id_users, 'id_userrole' => $f->id_userrole, 'id_usermenu' => $menu, 'nombre' => $nombre, 'icono' => $icono, 'accion' => $accion, 'id_menu' => $codigo, 'activo' => true, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $c = ['INTEGRACIONES-CONFIG', 'NOTIFICACIONES-PLANTILLAS'];
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $c)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $c)->delete();
    }
};
