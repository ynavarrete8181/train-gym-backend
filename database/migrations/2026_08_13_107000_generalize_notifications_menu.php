<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $menu = DB::table('seguridad.cpu_usermenu')->where('menu', 'Notificaciones')->value('id_usermenu');
        if (! $menu) {
            return;
        }

        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->update([
            'nombre' => 'Notificaciones de acceso', 'orden' => 4, 'updated_at' => now(),
        ]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->update([
            'nombre' => 'Notificaciones de acceso', 'orden' => 4, 'updated_at' => now(),
        ]);
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-PLANTILLAS')->update(['orden' => 2, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-PLANTILLAS')->update(['orden' => 2, 'updated_at' => now()]);

        $roles = DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->get();
        foreach ($roles as $rol) {
            foreach ([
                ['Campañas y envíos', 'campaign', 'notificaciones/campanias', 'NOTIFICACIONES-CAMPANIAS', 1],
                ['Historial', 'history', 'notificaciones/historial', 'NOTIFICACIONES-HISTORIAL', 3],
            ] as [$nombre, $icono, $accion, $codigo, $orden]) {
                DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                    'id_userrole' => $rol->id_userrole, 'id_usermenu' => $menu, 'nombre' => $nombre, 'icono' => $icono,
                    'accion' => $accion, 'id_menu' => $codigo, 'activo' => true, 'orden' => $orden,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $usuarios = DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->get();
        foreach ($usuarios as $usuario) {
            foreach ([
                ['Campañas y envíos', 'campaign', 'notificaciones/campanias', 'NOTIFICACIONES-CAMPANIAS', 1],
                ['Historial', 'history', 'notificaciones/historial', 'NOTIFICACIONES-HISTORIAL', 3],
            ] as [$nombre, $icono, $accion, $codigo, $orden]) {
                DB::table('seguridad.cpu_userfunction')->insertOrIgnore([
                    'id_users' => $usuario->id_users, 'id_userrole' => $usuario->id_userrole, 'id_usermenu' => $menu,
                    'nombre' => $nombre, 'icono' => $icono, 'accion' => $accion, 'id_menu' => $codigo,
                    'activo' => true, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $codigos = ['NOTIFICACIONES-CAMPANIAS', 'NOTIFICACIONES-HISTORIAL'];
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->update(['nombre' => 'Notificaciones de usuarios', 'orden' => 1, 'updated_at' => now()]);
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'NOTIFICACIONES-USUARIOS')->update(['nombre' => 'Notificaciones de usuarios', 'orden' => 1, 'updated_at' => now()]);
    }
};
