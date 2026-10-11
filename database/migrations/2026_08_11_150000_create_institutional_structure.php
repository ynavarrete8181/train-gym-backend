<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS institucional');
        Schema::create('institucional.sedes', function (Blueprint $t): void {
            $t->id('id_sede');
            $t->string('codigo', 30)->unique();
            $t->string('nombre', 180);
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('institucional.unidades', function (Blueprint $t): void {
            $t->id('id_unidad');
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 180);
            $t->string('tipo', 20);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->index('tipo');
        });
        Schema::create('institucional.sede_unidad', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('id_sede');
            $t->unsignedBigInteger('id_unidad');
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['id_sede', 'id_unidad']);
            $t->foreign('id_sede')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
            $t->foreign('id_unidad')->references('id_unidad')->on('institucional.unidades')->restrictOnDelete();
        });
        Schema::create('institucional.carreras_areas', function (Blueprint $t): void {
            $t->id('id_carrera_area');
            $t->unsignedBigInteger('id_unidad');
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 180);
            $t->string('tipo', 20);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->foreign('id_unidad')->references('id_unidad')->on('institucional.unidades')->restrictOnDelete();
            $t->index(['id_unidad', 'tipo']);
        });
        Schema::create('institucional.contextos', function (Blueprint $t): void {
            $t->id('id_contexto');
            $t->unsignedBigInteger('id_sede');
            $t->unsignedBigInteger('id_unidad');
            $t->unsignedBigInteger('id_carrera_area')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['id_sede', 'id_unidad', 'id_carrera_area']);
            $t->foreign('id_sede')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
            $t->foreign('id_unidad')->references('id_unidad')->on('institucional.unidades')->restrictOnDelete();
            $t->foreign('id_carrera_area')->references('id_carrera_area')->on('institucional.carreras_areas')->restrictOnDelete();
        });
        Schema::create('institucional.usuario_contexto', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('id_usuario');
            $t->unsignedBigInteger('id_contexto');
            $t->boolean('principal')->default(false);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['id_usuario', 'id_contexto']);
            $t->foreign('id_usuario')->references('id')->on('seguridad.users')->cascadeOnDelete();
            $t->foreign('id_contexto')->references('id_contexto')->on('institucional.contextos')->restrictOnDelete();
        });

        $menu = DB::table('seguridad.cpu_usermenu')->whereRaw("LOWER(menu) LIKE '%administraci%'")->value('id_usermenu');
        $roles = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->pluck('id_userrole');
        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore(['id_userrole' => $rol, 'id_usermenu' => $menu, 'nombre' => 'Estructura operativa', 'icono' => 'domain', 'accion' => 'institucional/estructura', 'id_menu' => 'INSTITUCIONAL-ESTRUCTURA', 'activo' => true, 'orden' => 5, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('seguridad.users')->whereIn('usr_tipo', $roles)->pluck('id') as $usuario) {
            $funcion = DB::table('seguridad.cpu_userrolefunction')->whereIn('id_userrole', $roles)->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->first();
            DB::table('seguridad.cpu_userfunction')->insertOrIgnore(['id_users' => $usuario, 'id_userrole' => $funcion->id_userrole, 'id_usermenu' => $funcion->id_usermenu, 'nombre' => $funcion->nombre, 'icono' => $funcion->icono, 'accion' => $funcion->accion, 'id_menu' => $funcion->id_menu, 'activo' => true, 'orden' => $funcion->orden, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'INSTITUCIONAL-ESTRUCTURA')->delete();
        foreach (['usuario_contexto', 'contextos', 'carreras_areas', 'sede_unidad', 'unidades', 'sedes'] as $tabla) {
            Schema::dropIfExists("institucional.$tabla");
        }
        DB::statement('DROP SCHEMA IF EXISTS institucional');
    }
};
