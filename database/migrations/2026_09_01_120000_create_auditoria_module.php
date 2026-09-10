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
        DB::statement('CREATE SCHEMA IF NOT EXISTS auditoria');

        if (! Schema::hasTable('auditoria.eventos')) {
            Schema::create('auditoria.eventos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->nullable()->constrained('seguridad.users')->nullOnDelete();
                $table->string('usuario_nombre', 160)->nullable();
                $table->string('rol', 60)->nullable();
                $table->string('modulo', 60);
                $table->string('tabla', 120)->nullable();
                $table->string('registro_id', 40)->nullable();
                $table->string('accion', 30);
                $table->string('descripcion', 255)->nullable();
                $table->jsonb('datos_antes')->nullable();
                $table->jsonb('datos_despues')->nullable();
                $table->string('ip', 60)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['modulo', 'created_at']);
                $table->index(['usuario_id', 'created_at']);
                $table->index(['tabla', 'registro_id']);
            });
        }

        if (! Schema::hasTable('auditoria.accesos')) {
            Schema::create('auditoria.accesos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->nullable()->constrained('seguridad.users')->nullOnDelete();
                $table->string('email', 160)->nullable();
                $table->string('tipo', 20);
                $table->string('motivo', 160)->nullable();
                $table->string('ip', 60)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['tipo', 'created_at']);
                $table->index(['usuario_id', 'created_at']);
            });
        }

        $this->registrarMenu();
    }

    public function down(): void
    {
        $codigos = ['AUDITORIA-EVENTOS', 'AUDITORIA-ACCESOS', 'AUDITORIA-RESUMEN'];

        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Auditoría')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('auditoria.accesos');
        Schema::dropIfExists('auditoria.eventos');
        DB::statement('DROP SCHEMA IF EXISTS auditoria');
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Auditoría')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Auditoría',
                'icono' => 'fact_check',
                'activo' => true,
                'orden' => 14,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'AUDITORIA-EVENTOS' => ['nombre' => 'Registro de actividad', 'accion' => '/auditoria-eventos', 'clave_pagina' => 'RegistroActividadPage', 'icono' => 'history', 'orden' => 1],
            'AUDITORIA-ACCESOS' => ['nombre' => 'Accesos al sistema', 'accion' => '/auditoria-accesos', 'clave_pagina' => 'AccesosSistemaPage', 'icono' => 'login', 'orden' => 2],
            'AUDITORIA-RESUMEN' => ['nombre' => 'Resumen', 'accion' => '/auditoria-resumen', 'clave_pagina' => 'ResumenAuditoriaPage', 'icono' => 'insights', 'orden' => 3],
        ];

        // Auditoría es información sensible: solo el rol ADMINISTRADOR la ve.
        foreach ($funciones as $codigo => $funcion) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                ['clave_pagina' => $funcion['clave_pagina'], 'created_at' => $now, 'updated_at' => $now]
            );

            foreach (DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->pluck('id_userrole') as $rolId) {
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
};
