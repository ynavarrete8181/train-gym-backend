<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = ['ACCESO-DISPOSITIVOS', 'ACCESO-CREDENCIALES', 'ACCESO-EVENTOS', 'ACCESO-ASISTENCIA'];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS acceso');

        if (! Schema::hasTable('acceso.dispositivos')) {
            Schema::create('acceso.dispositivos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->string('nombre', 120);
                $table->string('tipo', 40)->default('MANUAL');
                $table->string('proveedor', 80)->nullable();
                $table->string('identificador_externo', 120)->nullable();
                $table->boolean('activo')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('acceso.credenciales')) {
            Schema::create('acceso.credenciales', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cliente_id')->constrained('gimnasio.deportistas')->cascadeOnDelete();
                $table->string('tipo', 40)->default('QR');
                $table->string('codigo', 120)->unique();
                $table->string('estado', 30)->default('ACTIVA');
                $table->timestamp('vigencia_inicio')->nullable();
                $table->timestamp('vigencia_fin')->nullable();
                $table->timestamp('ultimo_uso_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['cliente_id', 'estado']);
            });
        }

        if (! Schema::hasTable('acceso.eventos')) {
            Schema::create('acceso.eventos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dispositivo_id')->nullable()->constrained('acceso.dispositivos')->nullOnDelete();
                $table->foreignId('cliente_id')->nullable()->constrained('gimnasio.deportistas')->nullOnDelete();
                $table->foreignId('credencial_id')->nullable()->constrained('acceso.credenciales')->nullOnDelete();
                $table->foreignId('membresia_id')->nullable()->constrained('gimnasio.membresias')->nullOnDelete();
                $table->string('codigo_credencial', 120)->nullable();
                $table->timestamp('fecha_hora')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->string('tipo_evento', 40)->default('INGRESO');
                $table->string('resultado', 30)->default('PENDIENTE');
                $table->string('motivo', 180)->nullable();
                $table->json('payload_raw')->nullable();
                $table->timestamps();
                $table->index(['cliente_id', 'fecha_hora']);
                $table->index('resultado');
            });
        }

        if (! Schema::hasTable('acceso.asistencias')) {
            Schema::create('acceso.asistencias', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('evento_id')->nullable()->constrained('acceso.eventos')->nullOnDelete();
                $table->foreignId('cliente_id')->constrained('gimnasio.deportistas')->cascadeOnDelete();
                $table->foreignId('membresia_id')->nullable()->constrained('gimnasio.membresias')->nullOnDelete();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->string('tipo', 40)->default('INGRESO');
                $table->string('metodo', 40)->default('MANUAL');
                $table->string('estado', 30)->default('VALIDA');
                $table->timestamp('fecha_hora')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
                $table->index(['cliente_id', 'fecha_hora']);
            });
        }

        $this->sembrarCatalogos();
        $this->registrarMenu();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Acceso')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('acceso.asistencias');
        Schema::dropIfExists('acceso.eventos');
        Schema::dropIfExists('acceso.credenciales');
        Schema::dropIfExists('acceso.dispositivos');
    }

    private function sembrarCatalogos(): void
    {
        DB::table('acceso.dispositivos')->updateOrInsert(
            ['nombre' => 'Control manual recepción'],
            ['tipo' => 'MANUAL', 'proveedor' => 'Revive', 'identificador_externo' => 'RECEPCION', 'activo' => true, 'metadata' => json_encode(['origen' => 'sistema_base']), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Acceso')->value('id_usermenu');
        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Acceso',
                'icono' => 'qr_code_scanner',
                'activo' => true,
                'orden' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'ACCESO-DISPOSITIVOS' => ['nombre' => 'Dispositivos', 'accion' => '/acceso-dispositivos', 'clave_pagina' => 'DispositivosAccesoPage', 'icono' => 'devices', 'orden' => 1, 'roles' => ['ADMINISTRADOR']],
            'ACCESO-CREDENCIALES' => ['nombre' => 'Credenciales', 'accion' => '/acceso-credenciales', 'clave_pagina' => 'CredencialesAccesoPage', 'icono' => 'badge', 'orden' => 2, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA']],
            'ACCESO-EVENTOS' => ['nombre' => 'Eventos', 'accion' => '/acceso-eventos', 'clave_pagina' => 'EventosAccesoPage', 'icono' => 'sensor_occupied', 'orden' => 3, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA']],
            'ACCESO-ASISTENCIA' => ['nombre' => 'Asistencia', 'accion' => '/asistencia', 'clave_pagina' => 'AsistenciaPage', 'icono' => 'how_to_reg', 'orden' => 4, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA', 'ENTRENADOR']],
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
};
