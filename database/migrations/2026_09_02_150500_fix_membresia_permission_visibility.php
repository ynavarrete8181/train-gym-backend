<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrige un error de la migración anterior (2026_09_02_150000): borrar por
 * completo las filas GIMNASIO-MEMBRESIAS de cpu_userrolefunction/cpu_userfunction
 * no solo quitaba el enlace del menú, también le quitaba el PERMISO a la API
 * (AutorizarFuncionBase / PermisoService revisan esas mismas filas por
 * id_menu + activo, sin importar el menú al que estén asociadas). Eso habría
 * roto la pestaña "Membresía" de la ficha del cliente, que depende de ese
 * mismo permiso.
 *
 * La corrección: en vez de borrar, se restauran/actualizan esas filas con
 * id_usermenu = NULL. MenuService arma el menú con un INNER JOIN contra
 * cpu_usermenu por id_usermenu, así que una fila sin id_usermenu no aparece
 * en el sidebar — pero PermisoService sigue autorizando la API porque solo
 * mira id_menu + activo. Resultado: el enlace queda oculto, el permiso
 * se mantiene intacto. Esta migración es segura sin importar si la anterior
 * ya se corrió (recrea las filas) o no (las actualiza).
 */
return new class extends Migration
{
    public function up(): void
    {
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
        // No-op: no hay una forma segura de "deshacer" esto sin volver a
        // romper el permiso. Si se necesita revertir al comportamiento
        // anterior, usar la migración 2026_09_02_150000 (down) directamente.
    }
};
