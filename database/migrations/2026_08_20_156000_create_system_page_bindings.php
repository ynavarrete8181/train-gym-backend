<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguridad.cpu_pagina_sistema', function (Blueprint $table): void {
            $table->bigIncrements('id_pagina_sistema');
            $table->string('id_menu', 120)->unique();
            $table->string('clave_pagina', 160);
            $table->timestamps();
        });

        $asociaciones = [
            'DASHBOARD' => 'DashboardPage',
            'SEGURIDAD-USUARIOS' => 'UsuariosPage',
            'SEGURIDAD-MENUS' => 'MenusPage',
            'SEGURIDAD-SUBMENUS' => 'SubmenusPage',
            'SEGURIDAD-ROLES' => 'RolesPage',
            'INSTITUCIONAL-SEDES' => 'SedesPage',
            'INSTITUCIONAL-UNIDADES' => 'UnidadesPage',
            'INSTITUCIONAL-CARRERAS-AREAS' => 'CarrerasAreasPage',
            'INSTITUCIONAL-CAMPOS-AMPLIOS' => 'CamposAmpliosPage',
            'INTEGRACIONES-CONFIG' => 'IntegracionesPage',
            'NOTIFICACIONES-COMUNICADOS' => 'CampaniasNotificacionPage',
            'NOTIFICACIONES-ACCESO' => 'NotificacionesUsuariosPage',
            'NOTIFICACIONES-PLANTILLAS' => 'PlantillasCorreoPage',
            'NOTIFICACIONES-HISTORIAL' => 'HistorialNotificacionesPage',
            'SEGURIDAD-PAGINAS' => 'PaginasSistemaPage',
        ];

        foreach ($asociaciones as $codigo => $pagina) {
            DB::table('seguridad.cpu_pagina_sistema')->insert([
                'id_menu' => $codigo,
                'clave_pagina' => $pagina,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menu = DB::table('seguridad.cpu_usermenu')->where('menu', 'Administración')->value('id_usermenu');
        if (! $menu) {
            return;
        }

        $roles = DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'SEGURIDAD-SUBMENUS')
            ->pluck('id_userrole')
            ->unique();

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol, 'id_menu' => 'SEGURIDAD-PAGINAS'],
                [
                    'id_usermenu' => $menu,
                    'nombre' => 'Páginas del sistema',
                    'icono' => 'web_asset',
                    'accion' => 'seguridad/paginas',
                    'activo' => true,
                    'orden' => 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $usuarios = DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'SEGURIDAD-SUBMENUS')
            ->select('id_users', 'id_userrole')
            ->distinct()
            ->get();

        foreach ($usuarios as $usuario) {
            DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                ['id_users' => $usuario->id_users, 'id_userrole' => $usuario->id_userrole, 'id_menu' => 'SEGURIDAD-PAGINAS'],
                [
                    'id_usermenu' => $menu,
                    'nombre' => 'Páginas del sistema',
                    'icono' => 'web_asset',
                    'accion' => 'seguridad/paginas',
                    'activo' => true,
                    'orden' => 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'SEGURIDAD-PAGINAS')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'SEGURIDAD-PAGINAS')->delete();
        Schema::dropIfExists('seguridad.cpu_pagina_sistema');
    }
};
