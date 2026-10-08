<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ADMINISTRADOR')
            ->value('id_userrole');

        if (! $adminId) {
            return;
        }

        $now = Carbon::now();

        $permisos = [
            [
                'id_menu' => 'GIMNASIO-MEMBRESIAS',
                'nombre' => 'Asignar membresía',
                'icono' => 'card_membership',
                'accion' => '/membresias',
                'orden' => 2,
            ],
            [
                'id_menu' => 'ENTRENAMIENTO-PROGRESO',
                'nombre' => 'Progreso físico',
                'icono' => 'monitor_weight',
                'accion' => '/deportistas',
                'orden' => 5,
            ],
        ];

        foreach ($permisos as $permiso) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $adminId,
                    'id_menu' => $permiso['id_menu'],
                ],
                [
                    'id_usermenu' => null,
                    'nombre' => $permiso['nombre'],
                    'icono' => $permiso['icono'],
                    'accion' => $permiso['accion'],
                    'orden' => $permiso['orden'],
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $usuariosAdmin = DB::table('seguridad.users')
            ->where('usr_tipo', $adminId)
            ->pluck('id');

        foreach ($usuariosAdmin as $usuarioId) {
            foreach ($permisos as $permiso) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuarioId,
                        'id_menu' => $permiso['id_menu'],
                    ],
                    [
                        'id_userrole' => $adminId,
                        'id_usermenu' => null,
                        'nombre' => $permiso['nombre'],
                        'icono' => $permiso['icono'],
                        'accion' => $permiso['accion'],
                        'orden' => $permiso['orden'],
                        'activo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        // No se revocan permisos operativos en rollback: esta migración
        // corrige una desincronización de capacidades del rol ADMINISTRADOR.
    }
};
