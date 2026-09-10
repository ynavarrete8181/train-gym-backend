<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'ENTRENAMIENTO-EJERCICIOS',
        'ENTRENAMIENTO-PLANES',
        'ENTRENAMIENTO-RUTINAS',
        'ENTRENAMIENTO-RM',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS entrenamiento');

        if (! Schema::hasTable('entrenamiento.ejercicios')) {
            Schema::create('entrenamiento.ejercicios', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 150)->unique();
                $table->string('grupo_muscular', 60);
                $table->string('equipamiento', 80)->default('Libre');
                $table->string('tipo_entrenamiento', 80)->nullable();
                $table->text('instrucciones')->nullable();
                $table->text('url_recurso')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->index(['grupo_muscular', 'activo']);
            });
        }

        if (! Schema::hasTable('entrenamiento.planes')) {
            Schema::create('entrenamiento.planes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cliente_id')->constrained('gimnasio.deportistas')->restrictOnDelete();
                $table->unsignedBigInteger('entrenador_id')->nullable();
                $table->string('nombre', 150);
                $table->text('objetivo')->nullable();
                $table->date('fecha_inicio');
                $table->date('fecha_fin')->nullable();
                $table->string('estado', 30)->default('BORRADOR');
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->foreign('entrenador_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->index(['cliente_id', 'estado']);
            });
        }

        if (! Schema::hasTable('entrenamiento.rutinas')) {
            Schema::create('entrenamiento.rutinas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')->constrained('entrenamiento.planes')->cascadeOnDelete();
                $table->foreignId('ejercicio_id')->constrained('entrenamiento.ejercicios')->restrictOnDelete();
                $table->unsignedSmallInteger('semana')->default(1);
                $table->string('dia', 30);
                $table->string('bloque', 120)->nullable();
                $table->unsignedSmallInteger('series')->default(1);
                $table->string('repeticiones', 50)->nullable();
                $table->decimal('carga_objetivo', 10, 2)->nullable();
                $table->string('tipo_carga', 30)->default('LIBRE');
                $table->unsignedSmallInteger('descanso_segundos')->nullable();
                $table->unsignedSmallInteger('orden')->default(1);
                $table->text('notas')->nullable();
                $table->timestamps();
                $table->index(['plan_id', 'semana', 'dia']);
            });
        }

        if (! Schema::hasTable('entrenamiento.rm_registros')) {
            Schema::create('entrenamiento.rm_registros', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cliente_id')->constrained('gimnasio.deportistas')->restrictOnDelete();
                $table->foreignId('ejercicio_id')->constrained('entrenamiento.ejercicios')->restrictOnDelete();
                $table->string('tipo_registro', 30)->default('ESTIMADO');
                $table->decimal('peso', 10, 2);
                $table->unsignedSmallInteger('repeticiones')->nullable();
                $table->decimal('rm_estimado', 10, 2);
                $table->date('fecha_registro')->default(DB::raw('CURRENT_DATE'));
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->index(['cliente_id', 'fecha_registro']);
            });
        }

        $this->sembrarEjercicios();
        $this->registrarMenu();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Entrenamiento')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('entrenamiento.rm_registros');
        Schema::dropIfExists('entrenamiento.rutinas');
        Schema::dropIfExists('entrenamiento.planes');
        Schema::dropIfExists('entrenamiento.ejercicios');
    }

    private function sembrarEjercicios(): void
    {
        $now = Carbon::now();
        $ejercicios = [
            ['Sentadilla', 'Piernas', 'Barra', 'Fuerza'],
            ['Peso muerto', 'Espalda', 'Barra', 'Fuerza'],
            ['Press banca', 'Pecho', 'Barra', 'Fuerza'],
            ['Press militar', 'Hombros', 'Barra', 'Fuerza'],
            ['Remo con barra', 'Espalda', 'Barra', 'Fuerza'],
            ['Dominadas', 'Espalda', 'Peso corporal', 'Funcional'],
            ['Fondos', 'Brazos', 'Peso corporal', 'Funcional'],
            ['Plancha abdominal', 'Abdomen', 'Libre', 'Core'],
            ['Burpees', 'Cardio', 'Libre', 'HIIT'],
            ['Zancadas', 'Piernas', 'Mancuernas', 'Funcional'],
            ['Curl bíceps', 'Brazos', 'Mancuernas', 'Hipertrofia'],
            ['Hip thrust', 'Piernas', 'Barra', 'Hipertrofia'],
        ];

        foreach ($ejercicios as [$nombre, $grupo, $equipo, $tipo]) {
            DB::table('entrenamiento.ejercicios')->updateOrInsert(
                ['nombre' => $nombre],
                [
                    'grupo_muscular' => $grupo,
                    'equipamiento' => $equipo,
                    'tipo_entrenamiento' => $tipo,
                    'instrucciones' => 'Registrar técnica, carga y progresión según criterio del entrenador.',
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Entrenamiento')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Entrenamiento',
                'icono' => 'fitness_center',
                'activo' => true,
                'orden' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'ENTRENAMIENTO-EJERCICIOS' => ['nombre' => 'Ejercicios', 'accion' => '/ejercicios', 'clave_pagina' => 'EjerciciosEntrenamientoPage', 'icono' => 'fitness_center', 'orden' => 1, 'roles' => ['ADMINISTRADOR', 'ENTRENADOR']],
            'ENTRENAMIENTO-PLANES' => ['nombre' => 'Planes de entrenamiento', 'accion' => '/planes-entrenamiento', 'clave_pagina' => 'PlanesEntrenamientoPage', 'icono' => 'assignment', 'orden' => 2, 'roles' => ['ADMINISTRADOR', 'ENTRENADOR']],
            'ENTRENAMIENTO-RUTINAS' => ['nombre' => 'Rutinas', 'accion' => '/rutinas', 'clave_pagina' => 'RutinasPage', 'icono' => 'view_week', 'orden' => 3, 'roles' => ['ADMINISTRADOR', 'ENTRENADOR']],
            'ENTRENAMIENTO-RM' => ['nombre' => 'Registros RM', 'accion' => '/registros-rm', 'clave_pagina' => 'RegistrosRmPage', 'icono' => 'monitor_weight', 'orden' => 4, 'roles' => ['ADMINISTRADOR', 'ENTRENADOR']],
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
                    [
                        'id_usermenu' => $menuId,
                        'nombre' => $funcion['nombre'],
                        'icono' => $funcion['icono'],
                        'accion' => $funcion['accion'],
                        'activo' => true,
                        'orden' => $funcion['orden'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                $usuarios = DB::table('seguridad.users')->where('usr_tipo', $rolId)->get(['id', 'usr_tipo']);
                foreach ($usuarios as $usuario) {
                    DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                        ['id_users' => $usuario->id, 'id_menu' => $codigo],
                        [
                            'id_userrole' => $usuario->usr_tipo,
                            'id_usermenu' => $menuId,
                            'nombre' => $funcion['nombre'],
                            'icono' => $funcion['icono'],
                            'accion' => $funcion['accion'],
                            'activo' => true,
                            'orden' => $funcion['orden'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }
    }
};
