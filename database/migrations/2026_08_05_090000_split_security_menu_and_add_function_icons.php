<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('base.cpu_userrolefunction', 'icono')) {
            Schema::table('base.cpu_userrolefunction', function (Blueprint $table): void {
                $table->string('icono', 120)->nullable()->after('nombre');
            });
        }

        if (! Schema::hasColumn('base.cpu_userfunction', 'icono')) {
            Schema::table('base.cpu_userfunction', function (Blueprint $table): void {
                $table->string('icono', 120)->nullable()->after('nombre');
            });
        }

        DB::table('base.cpu_userrolefunction')
            ->where('id_menu', 'DASHBOARD')
            ->update(['icono' => 'dashboard', 'updated_at' => now()]);

        DB::table('base.cpu_userrolefunction')
            ->where('id_menu', 'SEGURIDAD-USUARIOS')
            ->update(['icono' => 'manage_accounts', 'updated_at' => now()]);

        DB::table('base.cpu_userrolefunction')
            ->where('id_menu', 'SEGURIDAD-ROLES')
            ->update([
                'nombre' => 'Roles',
                'accion' => 'seguridad/roles',
                'icono' => 'admin_panel_settings',
                'orden' => 5,
                'updated_at' => now(),
            ]);

        $this->crearFuncionSeguridad('Menús', 'seguridad/menus', 'SEGURIDAD-MENUS', 'view_sidebar', 3);
        $this->crearFuncionSeguridad('Submenús', 'seguridad/submenus', 'SEGURIDAD-SUBMENUS', 'account_tree', 4);

        DB::table('base.cpu_userfunction')
            ->where('id_menu', 'SEGURIDAD-ROLES')
            ->update([
                'nombre' => 'Roles',
                'accion' => 'seguridad/roles',
                'icono' => 'admin_panel_settings',
                'orden' => 5,
                'updated_at' => now(),
            ]);

        $usuariosAdmin = DB::table('users')
            ->whereIn('usr_tipo', DB::table('base.cpu_userrole')->where('role', 'ADMINISTRADOR')->pluck('id_userrole'))
            ->pluck('id');

        $funcionesNuevas = DB::table('base.cpu_userrolefunction')
            ->whereIn('id_menu', ['SEGURIDAD-MENUS', 'SEGURIDAD-SUBMENUS'])
            ->get();

        foreach ($usuariosAdmin as $idUsuario) {
            foreach ($funcionesNuevas as $funcion) {
                DB::table('base.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $idUsuario, 'id_menu' => $funcion->id_menu],
                    [
                        'id_userrole' => $funcion->id_userrole,
                        'id_usermenu' => $funcion->id_usermenu,
                        'nombre' => $funcion->nombre,
                        'icono' => $funcion->icono,
                        'accion' => $funcion->accion,
                        'activo' => true,
                        'orden' => $funcion->orden,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('base.cpu_userfunction')->whereIn('id_menu', ['SEGURIDAD-MENUS', 'SEGURIDAD-SUBMENUS'])->delete();
        DB::table('base.cpu_userrolefunction')->whereIn('id_menu', ['SEGURIDAD-MENUS', 'SEGURIDAD-SUBMENUS'])->delete();

        DB::table('base.cpu_userrolefunction')
            ->where('id_menu', 'SEGURIDAD-ROLES')
            ->update([
                'nombre' => 'Roles y permisos',
                'accion' => 'seguridad/roles',
                'orden' => 3,
                'updated_at' => now(),
            ]);

        DB::table('base.cpu_userfunction')
            ->where('id_menu', 'SEGURIDAD-ROLES')
            ->update([
                'nombre' => 'Roles y permisos',
                'accion' => 'seguridad/roles',
                'orden' => 3,
                'updated_at' => now(),
            ]);
    }

    private function crearFuncionSeguridad(string $nombre, string $accion, string $codigo, string $icono, int $orden): void
    {
        $rolesAdmin = DB::table('base.cpu_userrole')->where('role', 'ADMINISTRADOR')->pluck('id_userrole');
        $menuAdministracion = DB::table('base.cpu_usermenu')->where('menu', 'Administración')->value('id_usermenu');

        foreach ($rolesAdmin as $idRol) {
            DB::table('base.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $idRol, 'id_menu' => $codigo],
                [
                    'id_usermenu' => $menuAdministracion,
                    'nombre' => $nombre,
                    'icono' => $icono,
                    'accion' => $accion,
                    'activo' => true,
                    'orden' => $orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
};
