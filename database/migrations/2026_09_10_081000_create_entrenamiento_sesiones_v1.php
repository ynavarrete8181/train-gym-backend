<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'ENTRENAMIENTO-SESIONES',
        'ENTRENAMIENTO-EJECUCION',
    ];

    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Sesión de entrenamiento
        |--------------------------------------------------------------------------
        |
        | Representa la ejecución real o programada de un entrenamiento.
        | No reemplaza entrenamiento.rutinas.
        |
        | rutinas = prescripción
        | sesiones = ejecución
        |
        */

        if (! Schema::hasTable('entrenamiento.sesiones')) {
            Schema::create('entrenamiento.sesiones', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('cliente_id')
                    ->constrained('gimnasio.deportistas')
                    ->restrictOnDelete();

                $table->foreignId('plan_id')
                    ->nullable()
                    ->constrained('entrenamiento.planes')
                    ->nullOnDelete();

                $table->unsignedSmallInteger('semana')->nullable();

                $table->string('dia', 30)->nullable();

                $table->string('nombre', 150)->nullable();

                $table->date('fecha_programada')->nullable();

                $table->timestamp('iniciado_at')->nullable();
                $table->timestamp('finalizado_at')->nullable();

                $table->string('estado', 30)->default('PROGRAMADA');

                $table->unsignedInteger('duracion_segundos')->nullable();

                $table->decimal('rpe_sesion', 4, 2)->nullable();

                $table->unsignedSmallInteger('energia')->nullable();

                $table->unsignedSmallInteger('dolor')->nullable();

                $table->text('observaciones')->nullable();

                $table->timestamps();

                $table->index(
                    ['cliente_id', 'fecha_programada'],
                    'ent_sesiones_cliente_fecha_idx'
                );

                $table->index(
                    ['plan_id', 'semana', 'dia'],
                    'ent_sesiones_plan_semana_dia_idx'
                );

                $table->index(
                    ['estado', 'fecha_programada'],
                    'ent_sesiones_estado_fecha_idx'
                );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Ejercicios de una sesión
        |--------------------------------------------------------------------------
        |
        | Snapshot de la prescripción.
        |
        | Aunque la rutina original cambie posteriormente, esta tabla conserva
        | exactamente lo que estaba programado cuando se creó la sesión.
        |
        */

        if (! Schema::hasTable('entrenamiento.sesiones_ejercicios')) {
            Schema::create('entrenamiento.sesiones_ejercicios', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('sesion_id')
                    ->constrained('entrenamiento.sesiones')
                    ->cascadeOnDelete();

                $table->foreignId('rutina_id')
                    ->nullable()
                    ->constrained('entrenamiento.rutinas')
                    ->nullOnDelete();

                $table->foreignId('ejercicio_id')
                    ->constrained('entrenamiento.ejercicios')
                    ->restrictOnDelete();

                $table->unsignedSmallInteger('orden')->default(1);

                $table->unsignedSmallInteger('series_objetivo')->default(1);

                $table->string('repeticiones_objetivo', 50)->nullable();

                $table->decimal('carga_objetivo', 10, 2)->nullable();

                $table->string('tipo_carga', 30)->default('LIBRE');

                $table->unsignedSmallInteger('descanso_objetivo_segundos')->nullable();

                $table->string('bloque', 120)->nullable();

                $table->text('notas')->nullable();

                $table->timestamps();

                $table->index(
                    ['sesion_id', 'orden'],
                    'ent_sesion_ejercicios_orden_idx'
                );

                $table->index(
                    ['ejercicio_id', 'sesion_id'],
                    'ent_sesion_ejercicios_ejercicio_idx'
                );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Series realmente ejecutadas
        |--------------------------------------------------------------------------
        |
        | Esta es una de las tablas centrales del futuro Revive:
        | peso, reps, RPE, RIR, descanso y cumplimiento real.
        |
        */

        if (! Schema::hasTable('entrenamiento.series_ejecutadas')) {
            Schema::create('entrenamiento.series_ejecutadas', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('sesion_ejercicio_id')
                    ->constrained('entrenamiento.sesiones_ejercicios')
                    ->cascadeOnDelete();

                $table->unsignedSmallInteger('numero_serie');

                $table->decimal('peso_kg', 10, 2)->nullable();

                $table->unsignedSmallInteger('repeticiones')->nullable();

                $table->decimal('rpe', 4, 2)->nullable();

                $table->decimal('rir', 4, 2)->nullable();

                $table->unsignedSmallInteger('descanso_segundos')->nullable();

                $table->unsignedInteger('duracion_segundos')->nullable();

                $table->boolean('completada')->default(false);

                $table->text('observaciones')->nullable();

                $table->timestamp('registrado_at')->nullable();

                $table->timestamps();

                $table->unique(
                    ['sesion_ejercicio_id', 'numero_serie'],
                    'ent_series_ejecutadas_numero_unique'
                );

                $table->index(
                    ['sesion_ejercicio_id', 'completada'],
                    'ent_series_ejecutadas_estado_idx'
                );
            });
        }

        $this->registrarPermisos();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')
            ->whereIn('id_menu', $this->codigos)
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->whereIn('id_menu', $this->codigos)
            ->delete();

        DB::table('seguridad.cpu_pagina_sistema')
            ->whereIn('id_menu', $this->codigos)
            ->delete();

        Schema::dropIfExists('entrenamiento.series_ejecutadas');
        Schema::dropIfExists('entrenamiento.sesiones_ejercicios');
        Schema::dropIfExists('entrenamiento.sesiones');
    }

    private function registrarPermisos(): void
    {
        $now = Carbon::now();

        $menuId = DB::table('seguridad.cpu_usermenu')
            ->where('menu', 'Entrenamiento')
            ->value('id_usermenu');

        /*
        |--------------------------------------------------------------------------
        | Sesiones
        |--------------------------------------------------------------------------
        |
        | Será visible en el menú administrativo.
        |
        */

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'ENTRENAMIENTO-SESIONES'],
            [
                'clave_pagina' => 'SesionesEntrenamientoPage',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Ejecución
        |--------------------------------------------------------------------------
        |
        | Permiso funcional interno. No requiere entrada adicional en el menú.
        |
        */

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'ENTRENAMIENTO-EJECUCION'],
            [
                'clave_pagina' => 'EjecucionEntrenamiento',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['ADMINISTRADOR', 'ENTRENADOR'])
            ->get(['id_userrole']);

        foreach ($roles as $rol) {
            /*
             * SESIONES: función visible.
             */
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_menu' => 'ENTRENAMIENTO-SESIONES',
                ],
                [
                    'id_usermenu' => $menuId,
                    'nombre' => 'Sesiones de entrenamiento',
                    'icono' => 'sports_gymnastics',
                    'accion' => '/sesiones-entrenamiento',
                    'activo' => true,
                    'orden' => 5,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            /*
             * EJECUCIÓN: función interna, sin menú lateral.
             */
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_menu' => 'ENTRENAMIENTO-EJECUCION',
                ],
                [
                    'id_usermenu' => null,
                    'nombre' => 'Ejecución de entrenamiento',
                    'icono' => 'exercise',
                    'accion' => '/sesiones-entrenamiento',
                    'activo' => true,
                    'orden' => 6,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $usuarios = DB::table('seguridad.users')
                ->where('usr_tipo', $rol->id_userrole)
                ->get(['id', 'usr_tipo']);

            foreach ($usuarios as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuario->id,
                        'id_menu' => 'ENTRENAMIENTO-SESIONES',
                    ],
                    [
                        'id_userrole' => $usuario->usr_tipo,
                        'id_usermenu' => $menuId,
                        'nombre' => 'Sesiones de entrenamiento',
                        'icono' => 'sports_gymnastics',
                        'accion' => '/sesiones-entrenamiento',
                        'activo' => true,
                        'orden' => 5,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuario->id,
                        'id_menu' => 'ENTRENAMIENTO-EJECUCION',
                    ],
                    [
                        'id_userrole' => $usuario->usr_tipo,
                        'id_usermenu' => null,
                        'nombre' => 'Ejecución de entrenamiento',
                        'icono' => 'exercise',
                        'accion' => '/sesiones-entrenamiento',
                        'activo' => true,
                        'orden' => 6,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
};
