<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'GIMNASIO-CATEGORIAS-SERVICIO',
        'GIMNASIO-SERVICIOS',
        'GIMNASIO-HORARIOS',
        'GIMNASIO-RESERVAS-DIA',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS gimnasio');

        if (! Schema::hasTable('gimnasio.categorias_servicio')) {
            Schema::create('gimnasio.categorias_servicio', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 150)->unique();
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gimnasio.servicios')) {
            Schema::create('gimnasio.servicios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('categoria_id')->constrained('gimnasio.categorias_servicio')->restrictOnDelete();
                $table->string('nombre', 150);
                $table->text('descripcion')->nullable();
                $table->unsignedSmallInteger('duracion_minutos')->default(60);
                $table->unsignedSmallInteger('capacidad_base')->default(1);
                $table->boolean('requiere_reserva')->default(true);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->unique(['categoria_id', 'nombre']);
            });
        }

        if (! Schema::hasTable('gimnasio.horarios_servicio')) {
            Schema::create('gimnasio.horarios_servicio', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('servicio_id')->constrained('gimnasio.servicios')->restrictOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->unsignedBigInteger('entrenador_id')->nullable();
                $table->string('dia_semana', 15);
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->unsignedSmallInteger('capacidad')->default(1);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
                $table->foreign('entrenador_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->index(['servicio_id', 'dia_semana']);
            });
        }

        if (! Schema::hasTable('gimnasio.reservas_dia')) {
            Schema::create('gimnasio.reservas_dia', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cliente_id')->constrained('gimnasio.deportistas')->restrictOnDelete();
                $table->foreignId('servicio_id')->constrained('gimnasio.servicios')->restrictOnDelete();
                $table->foreignId('horario_id')->nullable()->constrained('gimnasio.horarios_servicio')->nullOnDelete();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->date('fecha');
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->string('estado', 30)->default('RESERVADA');
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
                $table->index(['fecha', 'estado']);
            });
        }

        $this->sembrarCatalogos();
        $this->registrarMenuPermisos();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Servicios y Agenda')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('gimnasio.reservas_dia');
        Schema::dropIfExists('gimnasio.horarios_servicio');
        Schema::dropIfExists('gimnasio.servicios');
        Schema::dropIfExists('gimnasio.categorias_servicio');
    }

    private function sembrarCatalogos(): void
    {
        $now = Carbon::now();
        $categorias = [
            'Entrenamiento Revive' => 'Servicios principales del centro de entrenamiento físico Revive.',
            'Entrenamiento Personalizado' => 'Atención individual o semipersonalizada con coach asignado.',
            'Evaluación y Seguimiento' => 'Evaluaciones físicas, control técnico y seguimiento del deportista.',
            'Clases Grupales' => 'Sesiones grupales dirigidas por horario y cupo.',
            'Movilidad y Recuperación' => 'Movilidad, prevención, recuperación y trabajo correctivo.',
            'Revive Xpadel' => 'Servicios físicos y agenda operativa asociados a la sede Xpadel.',
        ];

        foreach ($categorias as $nombre => $descripcion) {
            DB::table('gimnasio.categorias_servicio')->updateOrInsert(
                ['nombre' => $nombre],
                ['descripcion' => $descripcion, 'activo' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $categoriaIds = DB::table('gimnasio.categorias_servicio')->pluck('id', 'nombre');
        $servicios = [
            ['Entrenamiento Revive', 'Acceso general con pizarra', 60, 12],
            ['Entrenamiento Revive', 'Fuerza e hipertrofia', 60, 10],
            ['Entrenamiento Revive', 'Entrenamiento funcional', 60, 12],
            ['Entrenamiento Revive', 'HIIT metabólico', 45, 12],
            ['Clases Grupales', 'Clase grupal funcional', 60, 15],
            ['Entrenamiento Personalizado', 'Personalizado 1:1', 60, 1],
            ['Entrenamiento Personalizado', 'Semi personalizado', 60, 4],
            ['Evaluación y Seguimiento', 'Evaluación física inicial', 45, 1],
            ['Evaluación y Seguimiento', 'Control y seguimiento', 30, 1],
            ['Movilidad y Recuperación', 'Movilidad y recuperación', 45, 6],
            ['Revive Xpadel', 'Preparación física Xpadel', 60, 8],
            ['Revive Xpadel', 'Acceso general Xpadel', 60, 12],
        ];

        foreach ($servicios as [$categoria, $nombre, $duracion, $capacidad]) {
            DB::table('gimnasio.servicios')->updateOrInsert(
                ['categoria_id' => $categoriaIds[$categoria] ?? null, 'nombre' => $nombre],
                [
                    'descripcion' => $nombre,
                    'duracion_minutos' => $duracion,
                    'capacidad_base' => $capacidad,
                    'requiere_reserva' => true,
                    'activo' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function registrarMenuPermisos(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Servicios y Agenda')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Servicios y Agenda',
                'icono' => 'event_available',
                'activo' => true,
                'orden' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'GIMNASIO-CATEGORIAS-SERVICIO' => ['nombre' => 'Categorías', 'accion' => '/categoria-servicio', 'clave_pagina' => 'CategoriasServicioPage', 'icono' => 'category', 'orden' => 1, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA']],
            'GIMNASIO-SERVICIOS' => ['nombre' => 'Servicios', 'accion' => '/servicios', 'clave_pagina' => 'ServiciosPage', 'icono' => 'fitness_center', 'orden' => 2, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA']],
            'GIMNASIO-HORARIOS' => ['nombre' => 'Horarios', 'accion' => '/horarios', 'clave_pagina' => 'HorariosPage', 'icono' => 'schedule', 'orden' => 3, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA', 'ENTRENADOR']],
            'GIMNASIO-RESERVAS-DIA' => ['nombre' => 'Reservas del Día', 'accion' => '/reservas-dia', 'clave_pagina' => 'ReservasDiaPage', 'icono' => 'event_available', 'orden' => 4, 'roles' => ['ADMINISTRADOR', 'RECEPCIONISTA', 'ENTRENADOR']],
        ];

        foreach ($funciones as $codigo => $datos) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                ['clave_pagina' => $datos['clave_pagina'], 'updated_at' => $now, 'created_at' => $now]
            );

            foreach ($datos['roles'] as $rol) {
                $rolId = DB::table('seguridad.cpu_userrole')->where('role', $rol)->value('id_userrole');
                if (! $rolId) {
                    continue;
                }

                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    ['id_userrole' => $rolId, 'id_menu' => $codigo],
                    [
                        'id_usermenu' => $menuId,
                        'nombre' => $datos['nombre'],
                        'icono' => $datos['icono'],
                        'accion' => $datos['accion'],
                        'activo' => true,
                        'orden' => $datos['orden'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        $this->sincronizarUsuarios($funciones, $menuId, $now);

        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->whereIn('id_menu', ['GIMNASIO-DISCIPLINAS', 'GIMNASIO-CLASES'])
                ->update(['activo' => false, 'updated_at' => $now]);
        }
    }

    private function sincronizarUsuarios(array $funciones, int $menuId, Carbon $now): void
    {
        foreach ($funciones as $codigo => $datos) {
            $rolesIds = DB::table('seguridad.cpu_userrole')
                ->whereIn('role', $datos['roles'])
                ->pluck('id_userrole');

            $usuarios = DB::table('seguridad.users')
                ->whereIn('usr_tipo', $rolesIds)
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
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
};
