<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ADMINISTRADOR')
            ->value('id_userrole');

        $supervisorId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'SUPERVISOR DE VENTAS')
            ->value('id_userrole');

        if (! $adminId || ! $supervisorId) {
            return;
        }

        $clienteVisible = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_userrole', $adminId)
            ->where('id_menu', 'GIMNASIO-DEPORTISTAS')
            ->whereNotNull('id_usermenu')
            ->where('activo', true)
            ->first();

        if (! $clienteVisible) {
            return;
        }

        DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
            [
                'id_userrole' => $supervisorId,
                'id_menu' => 'GIMNASIO-DEPORTISTAS',
            ],
            [
                'id_usermenu' => $clienteVisible->id_usermenu,
                'nombre' => 'Clientes',
                'icono' => $clienteVisible->icono,
                'accion' => $clienteVisible->accion,
                'orden' => $clienteVisible->orden,
                'activo' => true,
                'updated_at' => now(),
                'created_at' => $clienteVisible->created_at ?? now(),
            ]
        );

        $usuarios = DB::table('seguridad.users')
            ->where('usr_tipo', $supervisorId)
            ->pluck('id');

        foreach ($usuarios as $usuarioId) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                [
                    'id_users' => $usuarioId,
                    'id_menu' => 'GIMNASIO-DEPORTISTAS',
                ],
                [
                    'id_userrole' => $supervisorId,
                    'id_usermenu' => $clienteVisible->id_usermenu,
                    'nombre' => 'Clientes',
                    'icono' => $clienteVisible->icono,
                    'accion' => $clienteVisible->accion,
                    'orden' => $clienteVisible->orden,
                    'activo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // No elimina el permiso comercial; solo revierte la asociación visible
        // para evitar perder acceso API si el rol ya se encuentra en uso.
    }
};
