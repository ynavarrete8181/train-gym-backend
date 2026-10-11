<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'COMUNICACIONES-TIPOS',
        'COMUNICACIONES-SEGMENTOS',
        'COMUNICACIONES-MENSAJES',
        'COMUNICACIONES-PROGRAMACIONES',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS comunicaciones');

        if (! Schema::hasTable('comunicaciones.tipos_comunicacion')) {
            Schema::create('comunicaciones.tipos_comunicacion', function (Blueprint $table): void {
                $table->id();
                $table->string('codigo', 40)->unique();
                $table->string('nombre', 120);
                $table->text('descripcion')->nullable();
                $table->string('canal_preferido', 30)->default('SISTEMA');
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('comunicaciones.segmentos')) {
            Schema::create('comunicaciones.segmentos', function (Blueprint $table): void {
                $table->id();
                $table->string('codigo', 40)->unique();
                $table->string('nombre', 120);
                $table->text('descripcion')->nullable();
                $table->jsonb('criterios')->default(DB::raw("'{}'::jsonb"));
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('comunicaciones.mensajes')) {
            Schema::create('comunicaciones.mensajes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tipo_id')->nullable()->constrained('comunicaciones.tipos_comunicacion')->nullOnDelete();
                $table->foreignId('segmento_id')->nullable()->constrained('comunicaciones.segmentos')->nullOnDelete();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('titulo', 180);
                $table->text('contenido');
                $table->string('canal', 30)->default('SISTEMA');
                $table->string('estado', 30)->default('BORRADOR');
                $table->timestamp('programado_at')->nullable();
                $table->timestamp('enviado_at')->nullable();
                $table->unsignedBigInteger('notificacion_campania_id')->nullable();
                $table->timestamps();
                $table->foreign('created_by')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->foreign('notificacion_campania_id')->references('id')->on('notificaciones.campanias')->nullOnDelete();
                $table->index(['estado', 'programado_at']);
            });
        }

        if (! Schema::hasTable('comunicaciones.programaciones')) {
            Schema::create('comunicaciones.programaciones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('mensaje_id')->constrained('comunicaciones.mensajes')->cascadeOnDelete();
                $table->string('nombre', 120);
                $table->string('frecuencia', 30)->default('UNICA');
                $table->timestamp('proxima_ejecucion')->nullable();
                $table->string('estado', 30)->default('ACTIVA');
                $table->text('observaciones')->nullable();
                $table->timestamps();
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

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Comunicaciones')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('comunicaciones.programaciones');
        Schema::dropIfExists('comunicaciones.mensajes');
        Schema::dropIfExists('comunicaciones.segmentos');
        Schema::dropIfExists('comunicaciones.tipos_comunicacion');
    }

    private function sembrarCatalogos(): void
    {
        $now = Carbon::now();
        foreach ([
            ['PROMOCION', 'Promocion', 'Ofertas y campanas comerciales.', 'CORREO'],
            ['RECORDATORIO_MEMBRESIA', 'Recordatorio de membresia', 'Avisos de vencimiento o renovacion.', 'SISTEMA'],
            ['RESERVA', 'Reserva', 'Avisos relacionados con turnos y reservas.', 'PUSH'],
            ['CUMPLEANOS', 'Cumpleanos', 'Mensajes de cumpleanos para clientes.', 'SISTEMA'],
        ] as [$codigo, $nombre, $descripcion, $canal]) {
            DB::table('comunicaciones.tipos_comunicacion')->updateOrInsert(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'descripcion' => $descripcion, 'canal_preferido' => $canal, 'activo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        foreach ([
            ['TODOS_CLIENTES', 'Todos los clientes', 'Clientes registrados activos o en seguimiento.'],
            ['MEMBRESIAS_POR_VENCER', 'Membresias por vencer', 'Clientes con membresias proximas a vencer.'],
        ] as [$codigo, $nombre, $descripcion]) {
            DB::table('comunicaciones.segmentos')->updateOrInsert(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'descripcion' => $descripcion, 'criterios' => json_encode(['origen' => 'REVIVE']), 'activo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Comunicaciones')->value('id_usermenu');
        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Comunicaciones',
                'icono' => 'mark_email_unread',
                'activo' => true,
                'orden' => 12,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'COMUNICACIONES-TIPOS' => ['nombre' => 'Tipos', 'accion' => '/comunicaciones-tipos', 'clave_pagina' => 'TiposComunicacionPage', 'icono' => 'category', 'orden' => 1],
            'COMUNICACIONES-SEGMENTOS' => ['nombre' => 'Segmentos', 'accion' => '/comunicaciones-segmentos', 'clave_pagina' => 'SegmentosComunicacionPage', 'icono' => 'groups', 'orden' => 2],
            'COMUNICACIONES-MENSAJES' => ['nombre' => 'Mensajes', 'accion' => '/comunicaciones-mensajes', 'clave_pagina' => 'MensajesComunicacionPage', 'icono' => 'campaign', 'orden' => 3],
            'COMUNICACIONES-PROGRAMACIONES' => ['nombre' => 'Programaciones', 'accion' => '/comunicaciones-programaciones', 'clave_pagina' => 'ProgramacionesComunicacionPage', 'icono' => 'event_repeat', 'orden' => 4],
        ];

        $roles = DB::table('seguridad.cpu_userrole')->whereIn('role', ['ADMINISTRADOR', 'RECEPCIONISTA'])->pluck('id_userrole');
        foreach ($funciones as $codigo => $funcion) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                ['clave_pagina' => $funcion['clave_pagina'], 'created_at' => $now, 'updated_at' => $now]
            );

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
