<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS gimnasio');

        if (! Schema::hasTable('gimnasio.entrenadores')) {
            Schema::create('gimnasio.entrenadores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->unique()->constrained('seguridad.users')->cascadeOnDelete();
                $table->string('especialidad', 150)->nullable();
                $table->string('tipo', 50)->default('COACH');
                $table->string('estado', 30)->default('ACTIVO');
                $table->timestamps();

                $table->index('estado');
                $table->index('tipo');
            });
        }

        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Operaciones')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Operaciones',
                'icono' => 'fitness_center',
                'activo' => true,
                'orden' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funcion = [
            'id_menu' => 'GIMNASIO-ENTRENADORES',
            'nombre' => 'Entrenadores',
            'icono' => 'groups',
            'accion' => '/entrenadores',
            'clave_pagina' => 'EntrenadoresPage',
            'orden' => 4,
        ];

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => $funcion['id_menu']],
            [
                'clave_pagina' => $funcion['clave_pagina'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['ADMINISTRADOR', 'RECEPCIONISTA'])
            ->pluck('id_userrole');

        foreach ($roles as $idRol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $idRol, 'id_menu' => $funcion['id_menu']],
                [
                    'id_usermenu' => $menuId,
                    'nombre' => $funcion['nombre'],
                    'icono' => $funcion['icono'],
                    'accion' => $funcion['accion'],
                    'activo' => true,
                    'orden' => $funcion['orden'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $usuarios = DB::table('seguridad.users')->where('usr_tipo', $idRol)->pluck('id');

            foreach ($usuarios as $idUsuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $idUsuario, 'id_menu' => $funcion['id_menu']],
                    [
                        'id_userrole' => $idRol,
                        'id_usermenu' => $menuId,
                        'nombre' => $funcion['nombre'],
                        'icono' => $funcion['icono'],
                        'accion' => $funcion['accion'],
                        'activo' => true,
                        'orden' => $funcion['orden'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        $codigo = 'GIMNASIO-ENTRENADORES';

        DB::table('seguridad.cpu_userfunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', $codigo)->delete();

        Schema::dropIfExists('gimnasio.entrenadores');
    }
};
