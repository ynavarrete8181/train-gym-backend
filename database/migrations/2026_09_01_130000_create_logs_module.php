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
        DB::statement('CREATE SCHEMA IF NOT EXISTS logs');

        if (! Schema::hasTable('logs.eventos')) {
            Schema::create('logs.eventos', function (Blueprint $table): void {
                $table->id();
                $table->string('request_id', 80)->nullable();
                $table->string('nivel', 20)->default('INFO');
                $table->string('canal', 40)->default('BACKEND');
                $table->string('modulo', 80)->nullable();
                $table->string('accion', 120)->nullable();
                $table->text('mensaje');
                $table->foreignId('usuario_id')->nullable()->constrained('seguridad.users')->nullOnDelete();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->string('ip', 60)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->jsonb('contexto')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
                $table->index(['nivel', 'created_at']);
                $table->index(['modulo', 'created_at']);
                $table->index('request_id');
            });
        }

        if (! Schema::hasTable('logs.excepciones')) {
            Schema::create('logs.excepciones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('log_evento_id')->nullable()->constrained('logs.eventos')->nullOnDelete();
                $table->string('exception_class', 255)->nullable();
                $table->text('exception_message')->nullable();
                $table->string('archivo', 500)->nullable();
                $table->integer('linea')->nullable();
                $table->longText('stack_trace')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('logs.integraciones')) {
            Schema::create('logs.integraciones', function (Blueprint $table): void {
                $table->id();
                $table->string('request_id', 80)->nullable();
                $table->string('proveedor', 120);
                $table->string('tipo', 40);
                $table->string('direccion', 20);
                $table->string('endpoint', 500)->nullable();
                $table->string('metodo', 10)->nullable();
                $table->integer('status_code')->nullable();
                $table->jsonb('request_payload')->nullable();
                $table->jsonb('response_payload')->nullable();
                $table->text('error')->nullable();
                $table->integer('duracion_ms')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('proveedor');
                $table->index('request_id');
            });
        }

        $this->registrarMenu();
    }

    public function down(): void
    {
        $codigo = 'AUDITORIA-LOGS';

        DB::table('seguridad.cpu_userfunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', $codigo)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', $codigo)->delete();

        Schema::dropIfExists('logs.integraciones');
        Schema::dropIfExists('logs.excepciones');
        Schema::dropIfExists('logs.eventos');
        DB::statement('DROP SCHEMA IF EXISTS logs');
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Auditoría')->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        $codigo = 'AUDITORIA-LOGS';
        $funcion = ['nombre' => 'Errores del sistema', 'accion' => '/auditoria-logs', 'clave_pagina' => 'LogsTecnicosPage', 'icono' => 'bug_report', 'orden' => 4];

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
};
