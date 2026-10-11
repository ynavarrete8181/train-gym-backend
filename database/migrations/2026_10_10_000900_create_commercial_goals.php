<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS metas');

        Schema::create('metas.metas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sede_id');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->decimal('meta_ventas', 14, 2)->default(0);
            $table->decimal('meta_cobros', 14, 2)->default(0);
            $table->unsignedInteger('meta_membresias_nuevas')->default(0);
            $table->unsignedInteger('meta_renovaciones')->default(0);
            $table->string('estado', 20)->default('ACTIVA');
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->foreign('sede_id')
                ->references('id_sede')
                ->on('institucional.sedes')
                ->restrictOnDelete();

            $table->foreign('creado_por')
                ->references('id')
                ->on('seguridad.users')
                ->nullOnDelete();

            $table->foreign('actualizado_por')
                ->references('id')
                ->on('seguridad.users')
                ->nullOnDelete();

            $table->unique(['sede_id', 'anio', 'mes'], 'metas_sede_periodo_unique');
            $table->index(['anio', 'mes', 'estado']);
        });

        Schema::create('metas.meta_responsables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meta_id');
            $table->unsignedBigInteger('usuario_id');
            $table->decimal('meta_ventas', 14, 2)->default(0);
            $table->decimal('meta_cobros', 14, 2)->default(0);
            $table->unsignedInteger('meta_membresias_nuevas')->default(0);
            $table->unsignedInteger('meta_renovaciones')->default(0);
            $table->timestamps();

            $table->foreign('meta_id')
                ->references('id')
                ->on('metas.metas')
                ->cascadeOnDelete();

            $table->foreign('usuario_id')
                ->references('id')
                ->on('seguridad.users')
                ->restrictOnDelete();

            $table->unique(['meta_id', 'usuario_id'], 'meta_responsable_unique');
            $table->index('usuario_id');
        });

        $this->registrarMenu();
    }

    public function down(): void
    {
        $codigo = 'DASHBOARD-METAS-COMERCIALES';

        DB::table('seguridad.cpu_userfunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', $codigo)->delete();

        Schema::dropIfExists('metas.meta_responsables');
        Schema::dropIfExists('metas.metas');
    }

    private function registrarMenu(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Dashboard')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        $codigo = 'DASHBOARD-METAS-COMERCIALES';

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => $codigo],
            [
                'clave_pagina' => 'MetasComercialesPage',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'])
            ->where('activo', true)
            ->get(['id_userrole']);

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_usermenu' => $menuId,
                    'id_menu' => $codigo,
                    'accion' => '/dashboard-metas-comerciales',
                ],
                [
                    'nombre' => 'Metas comerciales',
                    'icono' => 'track_changes',
                    'activo' => true,
                    'orden' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            foreach (DB::table('seguridad.users')->where('usr_tipo', $rol->id_userrole)->get(['id']) as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuario->id,
                        'id_userrole' => $rol->id_userrole,
                        'id_usermenu' => $menuId,
                        'id_menu' => $codigo,
                        'accion' => '/dashboard-metas-comerciales',
                    ],
                    [
                        'nombre' => 'Metas comerciales',
                        'icono' => 'track_changes',
                        'activo' => true,
                        'orden' => 3,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
};
