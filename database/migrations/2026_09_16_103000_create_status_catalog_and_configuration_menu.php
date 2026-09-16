<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS configuracion');

        if (! Schema::hasTable('configuracion.estados_catalogo')) {
            Schema::create('configuracion.estados_catalogo', function (Blueprint $table): void {
                $table->id();
                $table->string('codigo', 80)->unique();
                $table->string('entidad', 60);
                $table->string('nombre', 120);
                $table->string('descripcion')->nullable();
                $table->string('color', 30)->default('default');
                $table->unsignedSmallInteger('orden')->default(1);
                $table->boolean('activo')->default(true);
                $table->boolean('es_inicial')->default(false);
                $table->boolean('es_final')->default(false);
                $table->boolean('protegido_sistema')->default(false);
                $table->timestamps();
                $table->index(['entidad', 'activo']);
            });
        }

        $estados = [
            ['MEM_PENDIENTE_PAGO', 'MEMBRESIA', 'Pendiente de pago', 'La membresía fue creada y espera confirmación de pago.', 'warning', 1, true, false],
            ['MEM_ACTIVA', 'MEMBRESIA', 'Activa', 'Membresía vigente y habilitada para uso.', 'success', 2, false, false],
            ['MEM_CONGELADA', 'MEMBRESIA', 'Congelada', 'Membresía pausada temporalmente.', 'info', 3, false, false],
            ['MEM_VENCIDA', 'MEMBRESIA', 'Vencida', 'La vigencia contractual terminó.', 'default', 4, false, true],
            ['MEM_CANCELADA', 'MEMBRESIA', 'Cancelada', 'Membresía cancelada administrativamente.', 'error', 5, false, true],
            ['VEN_PENDIENTE', 'VENTA', 'Pendiente de pago', 'Venta sin pagos confirmados suficientes.', 'warning', 1, true, false],
            ['VEN_PARCIAL', 'VENTA', 'Pago parcial', 'Venta con abonos confirmados, aún con saldo pendiente.', 'info', 2, false, false],
            ['VEN_PAGADA', 'VENTA', 'Pagada', 'Venta cubierta completamente por pagos confirmados.', 'success', 3, false, true],
            ['VEN_ANULADA', 'VENTA', 'Anulada', 'Venta anulada y sin efecto comercial.', 'error', 4, false, true],
            ['PAG_CONFIRMADO', 'PAGO', 'Confirmado', 'Pago confirmado y aplicado a una venta.', 'success', 1, true, true],
            ['PAG_ANULADO', 'PAGO', 'Anulado', 'Pago anulado.', 'error', 2, false, true],
            ['RES_PENDIENTE', 'RESERVA', 'Pendiente', 'Reserva creada a la espera de confirmación.', 'warning', 1, true, false],
            ['RES_CONFIRMADA', 'RESERVA', 'Confirmada', 'Reserva confirmada.', 'success', 2, false, false],
            ['RES_CANCELADA', 'RESERVA', 'Cancelada', 'Reserva cancelada.', 'error', 3, false, true],
        ];

        foreach ($estados as [$codigo, $entidad, $nombre, $descripcion, $color, $orden, $inicial, $final]) {
            DB::table('configuracion.estados_catalogo')->updateOrInsert(
                ['codigo' => $codigo],
                [
                    'entidad' => $entidad,
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'color' => $color,
                    'orden' => $orden,
                    'activo' => true,
                    'es_inicial' => $inicial,
                    'es_final' => $final,
                    'protegido_sistema' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Configuración')->value('id_usermenu');
        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Configuración',
                'icono' => 'settings',
                'activo' => true,
                'orden' => 90,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'id_usermenu');
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'CONFIGURACION-ESTADOS'],
            ['clave_pagina' => 'EstadosConfiguracionPage', 'created_at' => now(), 'updated_at' => now()]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR'])
            ->get(['id_userrole']);

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                ['id_userrole' => $rol->id_userrole, 'id_menu' => 'CONFIGURACION-ESTADOS'],
                [
                    'id_usermenu' => $menuId,
                    'nombre' => 'Estados',
                    'accion' => '/configuracion/estados',
                    'icono' => 'tune',
                    'activo' => true,
                    'orden' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $usuarios = DB::table('seguridad.users')->where('usr_tipo', $rol->id_userrole)->get(['id']);
            foreach ($usuarios as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users' => $usuario->id, 'id_menu' => 'CONFIGURACION-ESTADOS'],
                    [
                        'id_userrole' => $rol->id_userrole,
                        'id_usermenu' => $menuId,
                        'nombre' => 'Estados',
                        'accion' => '/configuracion/estados',
                        'icono' => 'tune',
                        'activo' => true,
                        'orden' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'CONFIGURACION-ESTADOS')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'CONFIGURACION-ESTADOS')->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', 'CONFIGURACION-ESTADOS')->delete();
        Schema::dropIfExists('configuracion.estados_catalogo');
    }
};
