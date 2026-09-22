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
        if (! Schema::hasColumn('gimnasio.reservas_dia', 'entrenador_id')) {
            Schema::table('gimnasio.reservas_dia', function (Blueprint $table): void {
                $table->foreignId('entrenador_id')
                    ->nullable()
                    ->after('servicio_id')
                    ->constrained('gimnasio.entrenadores')
                    ->nullOnDelete();

                $table->index(['entrenador_id', 'fecha', 'estado']);
            });
        }

        if (! Schema::hasTable('gimnasio.excepciones_horario')) {
            Schema::create('gimnasio.excepciones_horario', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entrenador_id')->constrained('gimnasio.entrenadores')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->string('tipo', 40)->default('NO_DISPONIBLE');
                $table->string('motivo', 180);
                $table->text('observaciones')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
                $table->index(['entrenador_id', 'fecha_inicio', 'fecha_fin']);
                $table->index(['sede_id', 'fecha_inicio', 'fecha_fin']);
            });
        }

        $this->sincronizarMenu();
    }

    public function down(): void
    {
        foreach (['seguridad.cpu_userfunction', 'seguridad.cpu_userrolefunction'] as $tabla) {
            DB::table($tabla)
                ->whereIn('id_menu', ['GIMNASIO-DISPONIBILIDAD', 'GIMNASIO-EXCEPCIONES-HORARIO'])
                ->delete();

            DB::table($tabla)
                ->where('id_menu', 'GIMNASIO-RESERVAS-DIA')
                ->update([
                    'nombre' => 'Reservas del Día',
                    'accion' => '/reservas-dia',
                    'orden' => 6,
                    'updated_at' => now(),
                ]);
        }

        DB::table('seguridad.cpu_pagina_sistema')
            ->whereIn('id_menu', ['GIMNASIO-DISPONIBILIDAD', 'GIMNASIO-EXCEPCIONES-HORARIO'])
            ->delete();

        DB::table('seguridad.cpu_pagina_sistema')
            ->where('id_menu', 'GIMNASIO-RESERVAS-DIA')
            ->update([
                'clave_pagina' => 'ReservasDiaPage',
                'updated_at' => now(),
            ]);

        Schema::dropIfExists('gimnasio.excepciones_horario');

        if (Schema::hasColumn('gimnasio.reservas_dia', 'entrenador_id')) {
            Schema::table('gimnasio.reservas_dia', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('entrenador_id');
            });
        }
    }

    private function sincronizarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Servicios y Agenda')
            ->value('id_usermenu');

        if (! $menuId) {
            return;
        }

        $funciones = [
            'GIMNASIO-DISPONIBILIDAD' => [
                'nombre' => 'Disponibilidad',
                'accion' => '/disponibilidad-agenda',
                'clave_pagina' => 'DisponibilidadAgendaPage',
                'icono' => 'event_available',
                'orden' => 6,
                'roles' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'RECEPCIONISTA', 'ENTRENADOR'],
            ],
            'GIMNASIO-RESERVAS-DIA' => [
                'nombre' => 'Reservas',
                'accion' => '/reservas',
                'clave_pagina' => 'ReservasPage',
                'icono' => 'event_note',
                'orden' => 7,
                'roles' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'RECEPCIONISTA', 'ENTRENADOR'],
            ],
            'GIMNASIO-EXCEPCIONES-HORARIO' => [
                'nombre' => 'Excepciones de horario',
                'accion' => '/excepciones-horario',
                'clave_pagina' => 'ExcepcionesHorarioPage',
                'icono' => 'event_busy',
                'orden' => 8,
                'roles' => ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'RECEPCIONISTA'],
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

            $roles = DB::table('seguridad.cpu_userrole')
                ->whereIn('role', $datos['roles'])
                ->get(['id_userrole']);

            foreach ($roles as $rol) {
                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    ['id_userrole' => $rol->id_userrole, 'id_menu' => $codigo],
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
                    ->where('usr_tipo', $rol->id_userrole)
                    ->get(['id', 'usr_tipo']);

                foreach ($usuarios as $usuario) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                        ['id_users' => $usuario->id, 'id_menu' => $codigo],
                        [
                            'id_userrole' => $usuario->usr_tipo,
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
