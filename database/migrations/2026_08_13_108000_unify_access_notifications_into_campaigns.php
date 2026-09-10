<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plantilla = DB::table('notificaciones.plantillas')->where('codigo', 'INVITACION_USUARIO')->value('id');
        if ($plantilla) {
            DB::statement("INSERT INTO notificaciones.notificaciones (usuario_id, plantilla_id, tipo, estado, correo_destino, created_at, updated_at)
                SELECT u.id, ?, 'INVITACION_USUARIO', 'PENDIENTE', u.email, NOW(), NOW()
                FROM seguridad.users u
                WHERE u.email IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM notificaciones.notificaciones n WHERE n.usuario_id = u.id AND n.tipo = 'INVITACION_USUARIO'
                )", [$plantilla]);
        }
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->delete();
    }

    public function down(): void
    {
        foreach (DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-CAMPANIAS')->get() as $f) {
            DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                'id_userrole' => $f->id_userrole, 'id_usermenu' => $f->id_usermenu, 'nombre' => 'Notificaciones de acceso',
                'icono' => 'mark_email_unread', 'accion' => 'notificaciones/usuarios', 'id_menu' => 'NOTIFICACIONES-USUARIOS',
                'activo' => true, 'orden' => 4, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-CAMPANIAS')->get() as $f) {
            DB::table('seguridad.cpu_userfunction')->insertOrIgnore([
                'id_users' => $f->id_users, 'id_userrole' => $f->id_userrole, 'id_usermenu' => $f->id_usermenu,
                'nombre' => 'Notificaciones de acceso', 'icono' => 'mark_email_unread', 'accion' => 'notificaciones/usuarios',
                'id_menu' => 'NOTIFICACIONES-USUARIOS', 'activo' => true, 'orden' => 4,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
