<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS base');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('cedula', 20)->nullable()->unique();
            $table->unsignedBigInteger('usr_tipo')->nullable();
            $table->unsignedSmallInteger('usr_estado')->default(1);
            $table->string('nombres', 120)->nullable();
            $table->string('apellidos', 120)->nullable();
        });

        Schema::create('base.cpu_userrole', function (Blueprint $table): void {
            $table->id('id_userrole');
            $table->string('role', 160)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('base.cpu_usermenu', function (Blueprint $table): void {
            $table->id('id_usermenu');
            $table->string('menu', 160);
            $table->string('icono', 120)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();
        });

        Schema::create('base.cpu_userrolefunction', function (Blueprint $table): void {
            $table->id('id_userrf');
            $table->unsignedBigInteger('id_userrole');
            $table->unsignedBigInteger('id_usermenu');
            $table->string('nombre', 180);
            $table->string('accion', 220);
            $table->string('id_menu', 120);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();
            $table->unique(['id_userrole', 'id_menu']);
        });

        Schema::create('base.cpu_userfunction', function (Blueprint $table): void {
            $table->id('id_userfunction');
            $table->unsignedBigInteger('id_users');
            $table->unsignedBigInteger('id_userrole');
            $table->unsignedBigInteger('id_usermenu');
            $table->string('nombre', 180);
            $table->string('accion', 220);
            $table->string('id_menu', 120);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();
            $table->unique(['id_users', 'id_menu']);
        });

        Schema::create('base.tokens_acceso', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_usuario');
            $table->string('nombre', 120)->default('api');
            $table->string('token_hash', 100)->unique();
            $table->timestamp('ultimo_uso_en')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->timestamps();
        });

        Schema::create('base.preferencias_usuario', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_usuario')->unique();
            $table->string('tema', 20)->default('sistema');
            $table->boolean('notificaciones_push')->default(true);
            $table->boolean('notificaciones_correo')->default(true);
            $table->boolean('notificaciones_internas')->default(true);
            $table->timestamps();
        });

        $rolAdmin = DB::table('base.cpu_userrole')->insertGetId([
            'role' => 'ADMINISTRADOR',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_userrole');

        $menuAdministracion = DB::table('base.cpu_usermenu')->insertGetId([
            'menu' => 'Administración',
            'icono' => 'admin_panel_settings',
            'activo' => true,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_usermenu');

        $funciones = [
            [$menuAdministracion, 'Dashboard', 'dashboard', 'DASHBOARD', 1],
            [$menuAdministracion, 'Usuarios', 'seguridad/usuarios', 'SEGURIDAD-USUARIOS', 2],
            [$menuAdministracion, 'Roles y permisos', 'seguridad/roles', 'SEGURIDAD-ROLES', 3],
        ];

        foreach ($funciones as [$idMenu, $nombre, $accion, $idMenuFuncion, $orden]) {
            DB::table('base.cpu_userrolefunction')->insert([
                'id_userrole' => $rolAdmin,
                'id_usermenu' => $idMenu,
                'nombre' => $nombre,
                'accion' => $accion,
                'id_menu' => $idMenuFuncion,
                'activo' => true,
                'orden' => $orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Administrador Revive',
            'email' => 'admin@revive.local',
            'password' => Hash::make('Admin12345*'),
            'usr_tipo' => $rolAdmin,
            'usr_estado' => 1,
            'nombres' => 'Administrador',
            'apellidos' => 'Base',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $funcionesRol = DB::table('base.cpu_userrolefunction')->where('id_userrole', $rolAdmin)->get();
        foreach ($funcionesRol as $funcion) {
            DB::table('base.cpu_userfunction')->insert([
                'id_users' => $adminId,
                'id_userrole' => $rolAdmin,
                'id_usermenu' => $funcion->id_usermenu,
                'nombre' => $funcion->nombre,
                'accion' => $funcion->accion,
                'id_menu' => $funcion->id_menu,
                'activo' => true,
                'orden' => $funcion->orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('base.preferencias_usuario')->insert([
            'id_usuario' => $adminId,
            'tema' => 'sistema',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('base.preferencias_usuario');
        Schema::dropIfExists('base.tokens_acceso');
        Schema::dropIfExists('base.cpu_userfunction');
        Schema::dropIfExists('base.cpu_userrolefunction');
        Schema::dropIfExists('base.cpu_usermenu');
        Schema::dropIfExists('base.cpu_userrole');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['cedula', 'usr_tipo', 'usr_estado', 'nombres', 'apellidos']);
        });
    }
};
