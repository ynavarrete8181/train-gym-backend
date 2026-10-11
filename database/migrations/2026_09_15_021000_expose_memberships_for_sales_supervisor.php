<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $supervisorId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'SUPERVISOR DE VENTAS')
            ->value('id_userrole');

        if (! $supervisorId) {
            return;
        }

        // GIMNASIO-MEMBRESIAS se mantiene oculto para otros roles, pero el
        // Supervisor de Ventas necesita una entrada visible para operar.
        $idMenuMembresias = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'GIMNASIO-PLANES')
            ->whereNotNull('id_usermenu')
            ->value('id_usermenu');

        if (! $idMenuMembresias) {
            return;
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $supervisorId)
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'id_usermenu' => $idMenuMembresias,
                'nombre' => 'Membresías',
                'accion' => '/membresias',
                'icono' => 'card_membership',
                'orden' => 1,
                'activo' => true,
                'updated_at' => now(),
            ]);

        $usuarios = DB::table('seguridad.users')
            ->where('usr_tipo', $supervisorId)
            ->pluck('id');

        DB::table('seguridad.cpu_userfunction')
            ->whereIn('id_users', $usuarios)
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'id_usermenu' => $idMenuMembresias,
                'nombre' => 'Membresías',
                'accion' => '/membresias',
                'icono' => 'card_membership',
                'orden' => 1,
                'activo' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $supervisorId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'SUPERVISOR DE VENTAS')
            ->value('id_userrole');

        if (! $supervisorId) {
            return;
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $supervisorId)
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'id_usermenu' => null,
                'nombre' => 'Asignar membresía',
                'orden' => 2,
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_userrole', $supervisorId)
            ->where('id_menu', 'GIMNASIO-MEMBRESIAS')
            ->update([
                'id_usermenu' => null,
                'nombre' => 'Asignar membresía',
                'orden' => 2,
                'updated_at' => now(),
            ]);
    }
};
