<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrige un error de la migración anterior (2026_09_02_150000): borrar por
 * completo las filas GIMNASIO-MEMBRESIAS de cpu_userrolefunction/cpu_userfunction
 * no solo quitaba el enlace del menú, también le quitaba el PERMISO a la API
 * (AutorizarFuncionBase / PermisoService revisan esas mismas filas por
 * id_menu + activo, sin importar el menú al que estén asociadas).
 *
 * La corrección: el permiso se conserva con id_usermenu = NULL. Para que esto
 * sea compatible con instalaciones creadas con la estructura base original,
 * primero se permite NULL en id_usermenu de las tablas de permisos por rol y
 * por usuario. MenuService arma el menú mediante la relación con cpu_usermenu,
 * así que una fila sin id_usermenu no aparece en el sidebar, mientras que la
 * autorización de API sigue encontrando id_menu + activo.
 *
 * Esta migración es idempotente respecto a los datos: recrea las filas si la
 * migración anterior las eliminó o las actualiza si todavía existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los permisos pueden existir sin entrada visible en el menú lateral.
        // PostgreSQL permite ejecutar DROP NOT NULL repetidamente sin afectar
        // los datos existentes, por lo que esto también es seguro en bases
        // donde la columna ya hubiera sido flexibilizada manualmente.
        DB::statement('ALTER TABLE seguridad.cpu_userrolefunction ALTER COLUMN id_usermenu DROP NOT NULL');
        DB::statement('ALTER TABLE seguridad.cpu_userfunction ALTER COLUMN id_usermenu DROP NOT NULL');

        $now = Carbon::now();

        $roles = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->pluck('id_userrole');

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol, 'id_menu' => 'GIMNASIO-MEMBRESIAS'],
                [
                    'id_usermenu' => null,
                    'nombre' => 'Asignar membresía',
                    'icono' => 'card_membership',
                    'accion' => '/membresias',
                    'orden' => 2,
                    'activo' => true,
                    'updated_at' => $now,
                ]
            );
        }

        $usuarios = DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->get(['id_users', 'id_userrole']);

        foreach ($usuarios as $u) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $u->id_users, 'id_menu' => 'GIMNASIO-MEMBRESIAS'],
                [
                    'id_userrole' => $u->id_userrole,
                    'id_usermenu' => null,
                    'nombre' => 'Asignar membresía',
                    'icono' => 'card_membership',
                    'accion' => '/membresias',
                    'orden' => 2,
                    'activo' => true,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // No se restaura NOT NULL: existen permisos válidos que deliberadamente
        // no deben pertenecer a un menú visible. Revertir esa nulabilidad podría
        // invalidar esas filas y volver a acoplar permisos con navegación.
    }
};
