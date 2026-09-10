<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $menu = DB::table('seguridad.cpu_usermenu')->insertGetId(['menu' => 'Estructura operativa', 'icono' => 'account_balance', 'activo' => true, 'orden' => 2, 'created_at' => now(), 'updated_at' => now()], 'id_usermenu');
        DB::table('seguridad.cpu_usermenu')->where('id_usermenu', '<>', $menu)->where('orden', '>=', 2)->increment('orden');
        $defs = [['Sedes', 'location_city', 'institucional/sedes', 'INSTITUCIONAL-SEDES', 1], ['Áreas operativas', 'account_balance', 'institucional/unidades', 'INSTITUCIONAL-UNIDADES', 2], ['Líneas de servicio', 'fitness_center', 'institucional/carreras-areas', 'INSTITUCIONAL-CARRERAS-AREAS', 3]];
        $roles = DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->pluck('id_userrole')->unique();
        foreach ($roles as $rol) {
            foreach ($defs as [$nombre,$icono,$accion,$codigo,$orden]) {
                DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore(['id_userrole' => $rol, 'id_usermenu' => $menu, 'nombre' => $nombre, 'icono' => $icono, 'accion' => $accion, 'id_menu' => $codigo, 'activo' => true, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now()]);
            }
        } foreach (DB::table('seguridad.cpu_userfunction')->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->get() as $f) {
            foreach ($defs as [$nombre,$icono,$accion,$codigo,$orden]) {
                DB::table('seguridad.cpu_userfunction')->insertOrIgnore(['id_users' => $f->id_users, 'id_userrole' => $f->id_userrole, 'id_usermenu' => $menu, 'nombre' => $nombre, 'icono' => $icono, 'accion' => $accion, 'id_menu' => $codigo, 'activo' => true, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now()]);
            }
        } DB::table('seguridad.cpu_userfunction')->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->delete();
    }

    public function down(): void
    {
        $c = ['INSTITUCIONAL-SEDES', 'INSTITUCIONAL-UNIDADES', 'INSTITUCIONAL-CARRERAS-AREAS'];
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $c)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $c)->delete();
        DB::table('seguridad.cpu_usermenu')->where('menu', 'Estructura operativa')->delete();
    }
};
