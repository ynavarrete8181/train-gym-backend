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

        if (! Schema::hasTable('gimnasio.jornadas')) {
            Schema::create('gimnasio.jornadas', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 120)->unique();
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gimnasio.jornada_detalles')) {
            Schema::create('gimnasio.jornada_detalles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('jornada_id')->constrained('gimnasio.jornadas')->cascadeOnDelete();
                $table->string('dia_semana', 15);
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['jornada_id', 'dia_semana']);
                $table->index(['dia_semana', 'hora_inicio', 'hora_fin']);
            });
        }

        if (! Schema::hasTable('gimnasio.recesos')) {
            Schema::create('gimnasio.recesos', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 120)->unique();
                $table->string('tipo', 30)->default('OTRO');
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gimnasio.asignaciones_horario_entrenador')) {
            Schema::create('gimnasio.asignaciones_horario_entrenador', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entrenador_id')->constrained('gimnasio.entrenadores')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->foreignId('jornada_id')->constrained('gimnasio.jornadas')->restrictOnDelete();
                $table->foreignId('receso_id')->nullable()->constrained('gimnasio.recesos')->nullOnDelete();
                $table->date('fecha_inicio');
                $table->date('fecha_fin')->nullable();
                $table->boolean('activo')->default(true);
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
                $table->index(['entrenador_id', 'activo']);
                $table->index(['sede_id', 'activo']);
                $table->index(['fecha_inicio', 'fecha_fin']);
            });
        }

        $this->sembrarJornadas();
        $this->registrarMenu();
    }

    public function down(): void
    {
        foreach (['seguridad.cpu_userfunction', 'seguridad.cpu_userrolefunction'] as $tabla) {
            DB::table($tabla)
                ->whereIn('id_menu', ['GIMNASIO-RECESOS', 'GIMNASIO-ASIGNACION-HORARIOS'])
                ->delete();
        }

        DB::table('seguridad.cpu_pagina_sistema')
            ->whereIn('id_menu', ['GIMNASIO-RECESOS', 'GIMNASIO-ASIGNACION-HORARIOS'])
            ->delete();

        Schema::dropIfExists('gimnasio.asignaciones_horario_entrenador');
        Schema::dropIfExists('gimnasio.recesos');
        Schema::dropIfExists('gimnasio.jornada_detalles');
        Schema::dropIfExists('gimnasio.jornadas');
    }

    private function sembrarJornadas(): void
    {
        $now = Carbon::now();
        $plantillas = [
            ['Jornada Matutina', 'Horario base de la mañana.', '08:00', '13:00'],
            ['Jornada Vespertina', 'Horario base de la tarde.', '13:00', '18:00'],
            ['Jornada Completa', 'Horario base de jornada completa.', '08:00', '18:00'],
        ];

        foreach ($plantillas as [$nombre, $descripcion, $inicio, $fin]) {
            $id = DB::table('gimnasio.jornadas')->where('nombre', $nombre)->value('id');

            if (! $id) {
                $id = DB::table('gimnasio.jornadas')->insertGetId([
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES'] as $dia) {
                DB::table('gimnasio.jornada_detalles')->updateOrInsert(
                    ['jornada_id' => $id, 'dia_semana' => $dia],
                    [
                        'hora_inicio' => $inicio,
                        'hora_fin' => $fin,
                        'activo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Servicios y Agenda')->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        $funciones = [
            'GIMNASIO-HORARIOS' => [
                'nombre' => 'Jornadas',
                'accion' => '/jornadas',
                'clave_pagina' => 'JornadasPage',
                'icono' => 'schedule',
                'orden' => 3,
            ],
            'GIMNASIO-RECESOS' => [
                'nombre' => 'Recesos',
                'accion' => '/recesos',
                'clave_pagina' => 'RecesosPage',
                'icono' => 'coffee',
                'orden' => 4,
            ],
            'GIMNASIO-ASIGNACION-HORARIOS' => [
                'nombre' => 'Asignación de horarios',
                'accion' => '/asignacion-horarios',
                'clave_pagina' => 'AsignacionHorariosPage',
                'icono' => 'calendar_month',
                'orden' => 5,
            ],
        ];

        foreach ($funciones as $codigo => $datos) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                [
                    'clave_pagina' => $datos['clave_pagina'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            foreach (['ADMINISTRADOR', 'RECEPCIONISTA'] as $rol) {
                $rolId = DB::table('seguridad.cpu_userrole')->where('role', $rol)->value('id_userrole');
                if (! $rolId) continue;

                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    ['id_userrole' => $rolId, 'id_menu' => $codigo],
                    [
                        'id_usermenu' => $menuId,
                        'nombre' => $datos['nombre'],
                        'icono' => $datos['icono'],
                        'accion' => $datos['accion'],
                        'activo' => true,
                        'orden' => $datos['orden'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                $usuarios = DB::table('seguridad.users')
                    ->where('usr_tipo', $rolId)
                    ->pluck('id');

                foreach ($usuarios as $usuarioId) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                        ['id_users' => $usuarioId, 'id_menu' => $codigo],
                        [
                            'id_userrole' => $rolId,
                            'id_usermenu' => $menuId,
                            'nombre' => $datos['nombre'],
                            'icono' => $datos['icono'],
                            'accion' => $datos['accion'],
                            'activo' => true,
                            'orden' => $datos['orden'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }
    }
};
