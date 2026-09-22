<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Servicios y Agenda')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        $funciones = [
            'GIMNASIO-HORARIOS' => [
                'nombre' => 'Jornadas',
                'accion' => '/jornadas',
                'clave_pagina' => 'JornadasPage',
                'icono' => 'schedule',
                'orden' => 3,
            ],
            'GIMNASIO-RECESOS' => [
                'nombre' => 'Recesos',
                'accion' => '/recesos',
                'clave_pagina' => 'RecesosPage',
                'icono' => 'coffee',
                'orden' => 4,
            ],
            'GIMNASIO-ASIGNACION-HORARIOS' => [
                'nombre' => 'Asignación de horarios',
                'accion' => '/asignacion-horarios',
                'clave_pagina' => 'AsignacionHorariosPage',
                'icono' => 'calendar_month',
                'orden' => 5,
            ],
        ];

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'RECEPCIONISTA'])
            ->get(['id_userrole', 'role']);

        foreach ($funciones as $codigo => $datos) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                [
                    'clave_pagina' => $datos['clave_pagina'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            foreach ($roles as $rol) {
                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    [
                        'id_userrole' => $rol->id_userrole,
                        'id_menu' => $codigo,
                    ],
                    [
                        'id_usermenu' => $menuId,
                        'nombre' => $datos['nombre'],
                        'icono' => $datos['icono'],
                        'accion' => $datos['accion'],
                        'activo' => true,
                        'orden' => $datos['orden'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                $usuarios = DB::table('seguridad.users')
                    ->where('usr_tipo', $rol->id_userrole)
                    ->pluck('id');

                foreach ($usuarios as $usuarioId) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                        [
                            'id_users' => $usuarioId,
                            'id_menu' => $codigo,
                        ],
                        [
                            'id_userrole' => $rol->id_userrole,
                            'id_usermenu' => $menuId,
                            'nombre' => $datos['nombre'],
                            'icono' => $datos['icono'],
                            'accion' => $datos['accion'],
                            'activo' => true,
                            'orden' => $datos['orden'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }

        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-RESERVAS-DIA')
                ->update([
                    'orden' => 6,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->whereIn('id_menu', ['GIMNASIO-RECESOS', 'GIMNASIO-ASIGNACION-HORARIOS'])
                ->delete();

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-HORARIOS')
                ->update([
                    'nombre' => 'Horarios',
                    'accion' => '/horarios',
                    'orden' => 3,
                    'updated_at' => now(),
                ]);

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-RESERVAS-DIA')
                ->update([
                    'orden' => 4,
                    'updated_at' => now(),
                ]);
        }

        DB::table('seguridad.cpu_pagina_sistema')
            ->whereIn('id_menu', ['GIMNASIO-RECESOS', 'GIMNASIO-ASIGNACION-HORARIOS'])
            ->delete();

        DB::table('seguridad.cpu_pagina_sistema')
            ->where('id_menu', 'GIMNASIO-HORARIOS')
            ->update([
                'clave_pagina' => 'HorariosPage',
                'updated_at' => now(),
            ]);
    }
};
