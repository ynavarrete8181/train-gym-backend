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
        $roles = DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'SEGURIDAD-USUARIOS')->pluck('id_userrole')->unique();
        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore(['id_userrole' => $rol, 'id_usermenu' => $menu, 'nombre' => 'Notificaciones de usuarios', 'icono' => 'mark_email_unread', 'accion' => 'notificaciones/usuarios', 'id_menu' => 'NOTIFICACIONES-USUARIOS', 'activo' => true, 'orden' => 5, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('seguridad.cpu_userfunction')->where('id_menu', 'SEGURIDAD-USUARIOS')->get() as $f) {
            DB::table('seguridad.cpu_userfunction')->insertOrIgnore(['id_users' => $f->id_users, 'id_userrole' => $f->id_userrole, 'id_usermenu' => $menu, 'nombre' => 'Notificaciones de usuarios', 'icono' => 'mark_email_unread', 'accion' => 'notificaciones/usuarios', 'id_menu' => 'NOTIFICACIONES-USUARIOS', 'activo' => true, 'orden' => 5, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->delete();
    }
};
