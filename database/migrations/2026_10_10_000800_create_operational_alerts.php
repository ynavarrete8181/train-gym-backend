<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS notificaciones');

        if (! Schema::hasTable('notificaciones.alertas_operativas')) {
            Schema::create('notificaciones.alertas_operativas', function (Blueprint $table): void {
                $table->id();
                $table->string('clave', 80);
                $table->string('tipo', 60);
                $table->string('nivel', 20)->default('INFO');
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->string('titulo', 160);
                $table->text('mensaje');
                $table->string('referencia_tipo', 80)->nullable();
                $table->unsignedBigInteger('referencia_id')->nullable();
                $table->string('vista', 80)->nullable();
                $table->string('estado', 20)->default('ACTIVA');
                $table->jsonb('contexto')->nullable();
                $table->timestamp('detectada_at')->useCurrent();
                $table->timestamp('ultima_deteccion_at')->useCurrent();
                $table->timestamp('ultima_notificacion_at')->nullable();
                $table->timestamp('resuelta_at')->nullable();
                $table->timestamps();

                $table->foreign('sede_id')
                    ->references('id_sede')
                    ->on('institucional.sedes')
                    ->nullOnDelete();

                $table->unique(
                    ['clave', 'sede_id', 'referencia_tipo', 'referencia_id'],
                    'alertas_operativas_identidad_unique'
                );
                $table->index(['estado', 'nivel', 'ultima_deteccion_at']);
                $table->index(['sede_id', 'estado']);
                $table->index(['tipo', 'estado']);
            });
        }

        $this->registrarMenu();
    }

    public function down(): void
    {
        $codigo = 'DASHBOARD-ALERTAS';

        DB::table('seguridad.cpu_userfunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', $codigo)->delete();

        Schema::dropIfExists('notificaciones.alertas_operativas');
    }

    private function registrarMenu(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Dashboard')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        $codigo = 'DASHBOARD-ALERTAS';

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => $codigo],
            [
                'clave_pagina' => 'AlertasOperativasPage',
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
                    'accion' => '/dashboard-alertas',
                ],
                [
                    'nombre' => 'Alertas operativas',
                    'icono' => 'notification_important',
                    'activo' => true,
                    'orden' => 2,
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
                        'accion' => '/dashboard-alertas',
                    ],
                    [
                        'nombre' => 'Alertas operativas',
                        'icono' => 'notification_important',
                        'activo' => true,
                        'orden' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
};
