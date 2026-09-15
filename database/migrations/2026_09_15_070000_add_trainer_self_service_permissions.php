<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $funciones = [
        [
            'id_menu' => 'ENTRENADOR-MIS-DEPORTISTAS',
            'nombre' => 'Mis deportistas',
            'accion' => '/mis-deportistas',
            'icono' => 'groups',
            'clave_pagina' => 'MisDeportistasPage',
            'orden' => 1,
        ],
        [
            'id_menu' => 'ENTRENADOR-MI-AGENDA',
            'nombre' => 'Mi agenda',
            'accion' => '/mi-agenda',
            'icono' => 'calendar_month',
            'clave_pagina' => 'MiAgendaEntrenadorPage',
            'orden' => 2,
        ],
    ];

    public function up(): void
    {
        $entrenadorId = DB::table('seguridad.cpu_userrole')
            ->where('role', 'ENTRENADOR')
            ->value('id_userrole');

        if (! $entrenadorId) {
            return;
        }

        $menuEntrenamiento = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'ENTRENAMIENTO-EJERCICIOS')
            ->whereNotNull('id_usermenu')
            ->value('id_usermenu');

        if (! $menuEntrenamiento) {
            return;
        }

        foreach ($this->funciones as $funcion) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $funcion['id_menu']],
                [
                    'clave_pagina' => $funcion['clave_pagina'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $entrenadorId,
                    'id_menu' => $funcion['id_menu'],
                ],
                [
                    'id_usermenu' => $menuEntrenamiento,
                    'nombre' => $funcion['nombre'],
                    'icono' => $funcion['icono'],
                    'accion' => $funcion['accion'],
                    'activo' => true,
                    'orden' => $funcion['orden'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $usuarios = DB::table('seguridad.users')
            ->where('usr_tipo', $entrenadorId)
            ->pluck('id');

        foreach ($usuarios as $usuarioId) {
            foreach ($this->funciones as $funcion) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuarioId,
                        'id_menu' => $funcion['id_menu'],
                    ],
                    [
                        'id_userrole' => $entrenadorId,
                        'id_usermenu' => $menuEntrenamiento,
                        'nombre' => $funcion['nombre'],
                        'icono' => $funcion['icono'],
                        'accion' => $funcion['accion'],
                        'activo' => true,
                        'orden' => $funcion['orden'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        $codigos = array_column($this->funciones, 'id_menu');

        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $codigos)->delete();
    }
};
