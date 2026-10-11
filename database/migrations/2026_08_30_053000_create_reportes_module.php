<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'REPORTES-DISPONIBLES',
        'REPORTES-HISTORIAL',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS reportes');

        Schema::create('reportes.definiciones', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nombre', 160);
            $table->string('categoria', 80);
            $table->text('descripcion')->nullable();
            $table->jsonb('parametros')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('reportes.ejecuciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definicion_id')->constrained('reportes.definiciones');
            $table->foreignId('usuario_id')->nullable()->constrained('seguridad.users');
            $table->string('formato', 20)->default('VISTA');
            $table->string('estado', 30)->default('GENERADO');
            $table->jsonb('filtros')->nullable();
            $table->jsonb('resultado')->nullable();
            $table->timestamp('generado_at')->nullable();
            $table->timestamps();
        });

        $this->sembrarDefiniciones();
        $this->registrarMenu();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Reportes')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('reportes.ejecuciones');
        Schema::dropIfExists('reportes.definiciones');
        DB::statement('DROP SCHEMA IF EXISTS reportes');
    }

    private function sembrarDefiniciones(): void
    {
        $now = Carbon::now();
        $reportes = [
            ['RESUMEN-OPERATIVO', 'Resumen operativo', 'Operación', 'Indicadores diarios de clientes, membresías, asistencias, ventas y reservas.'],
            ['MEMBRESIAS-VIGENCIA', 'Membresías por vigencia', 'Membresías', 'Control administrativo de membresías activas, vencidas y próximas a vencer.'],
            ['VENTAS-PERIODO', 'Ventas por período', 'Ventas', 'Consolidado de ventas, pagos y comprobantes por fechas y estado.'],
            ['ASISTENCIA-CLIENTES', 'Asistencia de clientes', 'Acceso', 'Registro de asistencias por cliente, sede y fecha.'],
            ['PROGRESO-FISICO', 'Progreso físico', 'Entrenamiento', 'Seguimiento de marcas y registros RM desde entrenamiento.'],
        ];

        foreach ($reportes as [$codigo, $nombre, $categoria, $descripcion]) {
            DB::table('reportes.definiciones')->updateOrInsert(
                ['codigo' => $codigo],
                [
                    'nombre' => $nombre,
                    'categoria' => $categoria,
                    'descripcion' => $descripcion,
                    'parametros' => json_encode(['fecha_inicio' => null, 'fecha_fin' => null]),
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
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Reportes')->value('id_usermenu');

        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Reportes',
                'icono' => 'dashboard',
                'activo' => true,
                'orden' => 13,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'REPORTES-DISPONIBLES' => ['nombre' => 'Reportes disponibles', 'accion' => '/reportes-disponibles', 'clave_pagina' => 'ReportesDisponiblesPage', 'icono' => 'summarize', 'orden' => 1],
            'REPORTES-HISTORIAL' => ['nombre' => 'Historial de reportes', 'accion' => '/reportes-historial', 'clave_pagina' => 'HistorialReportesPage', 'icono' => 'history', 'orden' => 2],
        ];

        foreach ($funciones as $codigo => $funcion) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
                ['id_menu' => $codigo],
                ['clave_pagina' => $funcion['clave_pagina'], 'created_at' => $now, 'updated_at' => $now]
            );

            foreach (DB::table('seguridad.cpu_userrole')->whereIn('role', ['ADMINISTRADOR', 'RECEPCIONISTA'])->pluck('id_userrole') as $rolId) {
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
