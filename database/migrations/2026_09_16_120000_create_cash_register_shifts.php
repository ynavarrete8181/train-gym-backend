<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ventas.turnos_caja')) {
            Schema::create('ventas.turnos_caja', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('caja_id');
                $table->unsignedBigInteger('usuario_id');
                $table->unsignedBigInteger('sede_id');
                $table->timestamp('fecha_apertura')->useCurrent();
                $table->timestamp('fecha_cierre')->nullable();
                $table->decimal('saldo_inicial', 12, 2)->default(0);
                $table->decimal('efectivo_esperado', 12, 2)->nullable();
                $table->decimal('efectivo_contado', 12, 2)->nullable();
                $table->decimal('diferencia', 12, 2)->nullable();
                $table->string('estado', 20)->default('ABIERTA');
                $table->text('observaciones_apertura')->nullable();
                $table->text('observaciones_cierre')->nullable();
                $table->unsignedBigInteger('cerrado_por')->nullable();
                $table->timestamps();

                $table->index(['sede_id', 'estado']);
                $table->index(['caja_id', 'estado']);
                $table->index(['usuario_id', 'estado']);
            });

            DB::statement('ALTER TABLE ventas.turnos_caja ADD CONSTRAINT turnos_caja_caja_fk FOREIGN KEY (caja_id) REFERENCES ventas.cajas(id)');
            DB::statement('ALTER TABLE ventas.turnos_caja ADD CONSTRAINT turnos_caja_usuario_fk FOREIGN KEY (usuario_id) REFERENCES seguridad.users(id)');
            DB::statement('ALTER TABLE ventas.turnos_caja ADD CONSTRAINT turnos_caja_sede_fk FOREIGN KEY (sede_id) REFERENCES institucional.sedes(id_sede)');
            DB::statement('ALTER TABLE ventas.turnos_caja ADD CONSTRAINT turnos_caja_cerrado_por_fk FOREIGN KEY (cerrado_por) REFERENCES seguridad.users(id)');
        }

        $menuVentas = DB::table('seguridad.cpu_usermenu')->where('nombre', 'Ventas')->first();
        $base = DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'VENTAS-CAJAS')->orderBy('id_userrole')->first();

        if ($menuVentas && $base) {
            $roles = DB::table('seguridad.cpu_userrole')
                ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS', 'CAJERO'])
                ->pluck('id_userrole');

            foreach ($roles as $rolId) {
                $existente = DB::table('seguridad.cpu_userrolefunction')
                    ->where('id_userrole', $rolId)
                    ->where('id_menu', 'VENTAS-TURNOS-CAJA')
                    ->first();

                if (! $existente) {
                    DB::table('seguridad.cpu_userrolefunction')->insert([
                        'id_userrole' => $rolId,
                        'id_usermenu' => $menuVentas->id_usermenu,
                        'nombre' => 'Turnos de caja',
                        'icono' => 'fa-solid fa-clock-rotate-left',
                        'accion' => '/ventas/turnos-caja',
                        'id_menu' => 'VENTAS-TURNOS-CAJA',
                        'activo' => true,
                        'orden' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_usermenu', $menuVentas->id_usermenu)
                ->where('id_menu', 'VENTAS-VENTAS')
                ->update(['orden' => 3, 'updated_at' => now()]);
            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_usermenu', $menuVentas->id_usermenu)
                ->where('id_menu', 'VENTAS-PAGOS')
                ->update(['orden' => 4, 'updated_at' => now()]);
            DB::table('seguridad.cpu_userrolefunction')
                ->where('id_usermenu', $menuVentas->id_usermenu)
                ->where('id_menu', 'VENTAS-COMPROBANTES')
                ->update(['orden' => 5, 'updated_at' => now()]);

            $usuarios = DB::table('seguridad.users')->whereIn('usr_tipo', $roles)->pluck('id');
            foreach ($usuarios as $usuarioId) {
                $rolId = DB::table('seguridad.users')->where('id', $usuarioId)->value('usr_tipo');
                $funcion = DB::table('seguridad.cpu_userrolefunction')
                    ->where('id_userrole', $rolId)
                    ->where('id_menu', 'VENTAS-TURNOS-CAJA')
                    ->first();
                if ($funcion && ! DB::table('seguridad.cpu_userfunction')->where('id_users', $usuarioId)->where('id_menu', 'VENTAS-TURNOS-CAJA')->exists()) {
                    DB::table('seguridad.cpu_userfunction')->insert([
                        'id_users' => $usuarioId,
                        'id_userrole' => $rolId,
                        'id_usermenu' => $funcion->id_usermenu,
                        'nombre' => $funcion->nombre,
                        'icono' => $funcion->icono,
                        'accion' => $funcion->accion,
                        'id_menu' => $funcion->id_menu,
                        'activo' => true,
                        'orden' => $funcion->orden,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'VENTAS-TURNOS-CAJA')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'VENTAS-TURNOS-CAJA')->delete();
        Schema::dropIfExists('ventas.turnos_caja');
    }
};
