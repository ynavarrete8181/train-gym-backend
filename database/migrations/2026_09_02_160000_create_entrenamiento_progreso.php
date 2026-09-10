<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Progreso físico (ficha corporal) del cliente: peso, talla, cintura, %
 * grasa corporal, IMC y masa magra (estos dos últimos calculados en el
 * servidor). Vive en el módulo Entrenamiento porque es la misma familia
 * de datos que Registros RM (seguimiento del cliente en el gym), no
 * administración de membresías.
 *
 * La función ENTRENAMIENTO-PROGRESO se registra con id_usermenu = NULL a
 * propósito: solo se usa desde la pestaña "Progreso" en la ficha del
 * cliente (DeportistasPage.jsx), no tiene página propia en el menú
 * lateral. Con id_usermenu = NULL, MenuService (que arma el menú con un
 * INNER JOIN sobre id_usermenu) no la muestra, pero
 * PermisoService::usuarioTieneAlgunaFuncion() (que solo filtra por
 * id_menu + activo) sigue autorizando la llamada a la API.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('entrenamiento.progresos_corporales')) {
            Schema::create('entrenamiento.progresos_corporales', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cliente_id')->constrained('gimnasio.deportistas')->cascadeOnDelete();
                $table->date('fecha_registro')->default(DB::raw('CURRENT_DATE'));
                $table->decimal('peso_kg', 6, 2)->nullable();
                $table->decimal('talla_cm', 6, 2)->nullable();
                $table->decimal('cintura_cm', 6, 2)->nullable();
                $table->decimal('grasa_corporal_pct', 5, 2)->nullable();
                $table->decimal('masa_magra_kg', 6, 2)->nullable();
                $table->decimal('imc', 5, 2)->nullable();
                $table->string('objetivo', 150)->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->index(['cliente_id', 'fecha_registro']);
            });
        }

        $this->registrarPermiso();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'ENTRENAMIENTO-PROGRESO')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'ENTRENAMIENTO-PROGRESO')->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', 'ENTRENAMIENTO-PROGRESO')->delete();

        Schema::dropIfExists('entrenamiento.progresos_corporales');
    }

    private function registrarPermiso(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Entrenamiento')->value('id_usermenu');

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'ENTRENAMIENTO-PROGRESO'],
            ['clave_pagina' => 'ProgresoFichaCliente', 'created_at' => $now, 'updated_at' => $now]
        );

        $roles = DB::table('seguridad.cpu_userrole')->whereIn('role', ['ADMINISTRADOR', 'ENTRENADOR'])->pluck('id_userrole');

        foreach ($roles as $rolId) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rolId, 'id_menu' => 'ENTRENAMIENTO-PROGRESO'],
                [
                    'id_usermenu' => null,
                    'nombre' => 'Progreso físico',
                    'icono' => 'monitor_weight',
                    'accion' => '/deportistas',
                    'activo' => true,
                    'orden' => 5,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $usuarios = DB::table('seguridad.users')->where('usr_tipo', $rolId)->get(['id', 'usr_tipo']);
            foreach ($usuarios as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $usuario->id, 'id_menu' => 'ENTRENAMIENTO-PROGRESO'],
                    [
                        'id_userrole' => $usuario->usr_tipo,
                        'id_usermenu' => null,
                        'nombre' => 'Progreso físico',
                        'icono' => 'monitor_weight',
                        'accion' => '/deportistas',
                        'activo' => true,
                        'orden' => 5,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
};
