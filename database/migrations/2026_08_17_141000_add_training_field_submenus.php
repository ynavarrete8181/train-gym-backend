<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $menu = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Estructura operativa')
            ->value('id_usermenu');

        if (! $menu) {
            return;
        }

        $def = ['Categorías de servicio', 'category', 'institucional/campos-amplios', 'INSTITUCIONAL-CAMPOS-AMPLIOS', 4];
        [$nombre, $icono, $accion, $codigo, $orden] = $def;

        $roles = DB::table('seguridad.cpu_userrolefunction')
            ->whereIn('id_menu', ['INSTITUCIONAL-SEDES', 'INSTITUCIONAL-UNIDADES', 'INSTITUCIONAL-CARRERAS-AREAS'])
            ->pluck('id_userrole')
            ->unique();

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol, 'id_menu' => $codigo],
                [
                    'id_usermenu' => $menu,
                    'nombre' => $nombre,
                    'icono' => $icono,
                    'accion' => $accion,
                    'activo' => true,
                    'orden' => $orden,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $usuarios = DB::table('seguridad.cpu_userfunction')
            ->whereIn('id_menu', ['INSTITUCIONAL-SEDES', 'INSTITUCIONAL-UNIDADES', 'INSTITUCIONAL-CARRERAS-AREAS'])
            ->select('id_users', 'id_userrole')
            ->distinct()
            ->get();

        foreach ($usuarios as $usuario) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $usuario->id_users, 'id_userrole' => $usuario->id_userrole, 'id_menu' => $codigo],
                [
                    'id_usermenu' => $menu,
                    'nombre' => $nombre,
                    'icono' => $icono,
                    'accion' => $accion,
                    'activo' => true,
                    'orden' => $orden,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'INSTITUCIONAL-CAMPOS-AMPLIOS')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'INSTITUCIONAL-CAMPOS-AMPLIOS')->delete();
    }
};
