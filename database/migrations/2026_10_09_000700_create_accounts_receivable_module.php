<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS cuentas_cobrar');

        Schema::create('cuentas_cobrar.cuentas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('venta_id')->unique();
            $table->date('fecha_vencimiento');
            $table->string('estado', 20)->default('ABIERTA');
            $table->string('prioridad', 20)->default('NORMAL');
            $table->unsignedBigInteger('responsable_id')->nullable();
            $table->timestamp('ultima_gestion_at')->nullable();
            $table->timestamp('proxima_gestion_at')->nullable();
            $table->timestamp('cerrada_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->foreign('venta_id')->references('id')->on('ventas.ventas')->cascadeOnDelete();
            $table->foreign('responsable_id')->references('id')->on('seguridad.users')->nullOnDelete();
            $table->index(['estado', 'fecha_vencimiento']);
            $table->index(['responsable_id', 'estado']);
        });

        Schema::create('cuentas_cobrar.gestiones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cuenta_id');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('tipo', 30);
            $table->string('resultado', 40)->nullable();
            $table->text('detalle');
            $table->timestamp('gestion_at');
            $table->timestamp('proxima_gestion_at')->nullable();
            $table->timestamps();

            $table->foreign('cuenta_id')->references('id')->on('cuentas_cobrar.cuentas')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
            $table->index(['cuenta_id', 'gestion_at']);
        });

        Schema::create('cuentas_cobrar.compromisos_pago', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cuenta_id');
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->decimal('monto', 12, 2);
            $table->date('fecha_compromiso');
            $table->string('estado', 20)->default('PENDIENTE');
            $table->text('observaciones')->nullable();
            $table->timestamp('cumplido_at')->nullable();
            $table->timestamps();

            $table->foreign('cuenta_id')->references('id')->on('cuentas_cobrar.cuentas')->cascadeOnDelete();
            $table->foreign('creado_por')->references('id')->on('seguridad.users')->nullOnDelete();
            $table->foreign('pago_id')->references('id')->on('ventas.pagos')->nullOnDelete();
            $table->index(['cuenta_id', 'estado', 'fecha_compromiso']);
        });

        // Backfill únicamente deudas abiertas existentes. La venta/pagos siguen siendo
        // la fuente financiera; aquí se crea el encabezado de gestión de cartera.
        DB::statement("
            INSERT INTO cuentas_cobrar.cuentas
                (venta_id, fecha_vencimiento, estado, prioridad, created_at, updated_at)
            SELECT
                v.id,
                (
                    COALESCE(mp.fecha_inicio, v.fecha_venta::date)
                    + COALESCE(m.dias_gracia, 0)
                )::date AS fecha_vencimiento,
                'ABIERTA',
                'NORMAL',
                NOW(),
                NOW()
            FROM ventas.ventas v
            LEFT JOIN membresias.membresias m ON m.id = v.membresia_id
            LEFT JOIN membresias.membresia_periodos mp ON mp.venta_id = v.id
            WHERE v.estado IN ('PENDIENTE', 'PARCIAL')
              AND (
                  v.total - COALESCE((
                      SELECT SUM(p.monto)
                      FROM ventas.pagos p
                      WHERE p.venta_id = v.id
                        AND p.estado = 'CONFIRMADO'
                  ), 0)
              ) > 0
            ON CONFLICT (venta_id) DO NOTHING
        ");

        $this->registrarMenu();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->where('id_menu', 'VENTAS-CARTERA')->delete();
        DB::table('seguridad.cpu_userrolefunction')->where('id_menu', 'VENTAS-CARTERA')->delete();
        DB::table('seguridad.cpu_pagina_sistema')->where('id_menu', 'VENTAS-CARTERA')->delete();

        Schema::dropIfExists('cuentas_cobrar.compromisos_pago');
        Schema::dropIfExists('cuentas_cobrar.gestiones');
        Schema::dropIfExists('cuentas_cobrar.cuentas');
        DB::statement('DROP SCHEMA IF EXISTS cuentas_cobrar');
    }

    private function registrarMenu(): void
    {
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Ventas')->value('id_usermenu');
        if (! $menuId) {
            return;
        }

        DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(
            ['id_menu' => 'VENTAS-CARTERA'],
            [
                'clave_pagina' => 'CarteraPage',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $roles = DB::table('seguridad.cpu_userrole')
            ->whereIn('role', ['SUPERADMINISTRADOR', 'ADMINISTRADOR', 'SUPERVISOR DE VENTAS'])
            ->where('activo', true)
            ->get(['id_userrole']);

        foreach ($roles as $rol) {
            DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                [
                    'id_userrole' => $rol->id_userrole,
                    'id_usermenu' => $menuId,
                    'id_menu' => 'VENTAS-CARTERA',
                    'accion' => '/cartera',
                ],
                [
                    'nombre' => 'Cartera',
                    'icono' => 'account_balance_wallet',
                    'activo' => true,
                    'orden' => 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            foreach (DB::table('seguridad.users')->where('usr_tipo', $rol->id_userrole)->get(['id']) as $usuario) {
                DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    [
                        'id_users' => $usuario->id,
                        'id_userrole' => $rol->id_userrole,
                        'id_usermenu' => $menuId,
                        'id_menu' => 'VENTAS-CARTERA',
                        'accion' => '/cartera',
                    ],
                    [
                        'nombre' => 'Cartera',
                        'icono' => 'account_balance_wallet',
                        'activo' => true,
                        'orden' => 5,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
};
