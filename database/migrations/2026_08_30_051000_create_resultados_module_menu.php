<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $codigos = [
        'RESULTADOS-RESUMEN',
        'RESULTADOS-ASISTENCIA',
        'RESULTADOS-VENTAS',
        'RESULTADOS-PROGRESO',
    ];

    public function up(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Resultados')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Resultados',
                'icono' => 'trending_up',
                'activo' => true,
                'orden' => 11,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'RESULTADOS-RESUMEN' => ['nombre' => 'Resumen operativo', 'accion' => '/resultados-resumen', 'clave_pagina' => 'ResumenResultadosPage', 'icono' => 'monitoring', 'orden' => 1, 'roles' => ['ADMINISTRADOR']],
            'RESULTADOS-ASISTENCIA' => ['nombre' => 'Asistencia', 'accion' => '/resultados-asistencia', 'clave_pagina' => 'ResultadosAsistenciaPage', 'icono' => 'how_to_reg', 'orden' => 2, 'roles' => ['ADMINISTRADOR', 'ENTRENADOR']],
            'RESULTADOS-VENTAS' => ['nombre' => 'Ventas', 'accion' => '/resultados-ventas', 'clave_pagina' => 'ResultadosVentasPage', 'icono' => 'paid', 'orden' => 3, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
            'RESULTADOS-PROGRESO' => ['nombre' => 'Progreso físico', 'accion' => '/resultados-progreso', 'clave_pagina' => 'ResultadosProgresoPage', 'icono' => 'fitness_center', 'orden' => 4, 'roles' => ['ADMINISTRADOR', 'ENTRENADOR']],
        ];

        foreach ($funciones as $codigo => $funcion) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                ['clave_pagina' => $funcion['clave_pagina'], 'created_at' => $now, 'updated_at' => $now]
            );

            $roles = DB::table('seguridad.cpu_userrole')->whereIn('role', $funcion['roles'])->pluck('id_userrole');
            foreach ($roles as $rolId) {
                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    ['id_userrole' => $rolId, 'id_menu' => $codigo],
                    ['id_usermenu' => $menuId, 'nombre' => $funcion['nombre'], 'icono' => $funcion['icono'], 'accion' => $funcion['accion'], 'activo' => true, 'orden' => $funcion['orden'], 'created_at' => $now, 'updated_at' => $now]
                );

                foreach (DB::table('seguridad.users')->where('usr_tipo', $rolId)->get(['id', 'usr_tipo']) as $usuario) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                        ['id_users' => $usuario->id, 'id_menu' => $codigo],
                        ['id_userrole' => $usuario->usr_tipo, 'id_usermenu' => $menuId, 'nombre' => $funcion['nombre'], 'icono' => $funcion['icono'], 'accion' => $funcion['accion'], 'activo' => true, 'orden' => $funcion['orden'], 'created_at' => $now, 'updated_at' => $now]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Resultados')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }
    }
};
