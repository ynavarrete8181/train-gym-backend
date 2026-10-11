<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'VENTAS-CAJAS',
        'VENTAS-VENTAS',
        'VENTAS-PAGOS',
        'VENTAS-COMPROBANTES',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ventas');

        if (! Schema::hasTable('ventas.cajas')) {
            Schema::create('ventas.cajas', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->string('codigo', 40)->unique();
                $table->string('nombre', 120);
                $table->text('descripcion')->nullable();
                $table->decimal('saldo_inicial', 12, 2)->default(0);
                $table->boolean('activa')->default(true);
                $table->timestamps();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('ventas.ventas')) {
            Schema::create('ventas.ventas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cliente_id')->nullable()->constrained('gimnasio.deportistas')->nullOnDelete();
                $table->foreignId('membresia_id')->nullable()->constrained('gimnasio.membresias')->nullOnDelete();
                $table->foreignId('caja_id')->nullable()->constrained('ventas.cajas')->nullOnDelete();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('numero', 60)->unique();
                $table->string('tipo_venta', 40)->default('PRODUCTO');
                $table->string('concepto', 180);
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('descuento', 12, 2)->default(0);
                $table->decimal('impuesto', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('estado', 30)->default('PENDIENTE');
                $table->timestamp('fecha_venta')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->index(['cliente_id', 'estado']);
                $table->index('fecha_venta');
            });
        }

        if (! Schema::hasTable('ventas.venta_detalles')) {
            Schema::create('ventas.venta_detalles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('venta_id')->constrained('ventas.ventas')->cascadeOnDelete();
                $table->foreignId('producto_id')->nullable()->constrained('inventario.productos')->nullOnDelete();
                $table->string('descripcion', 180);
                $table->decimal('cantidad', 12, 2)->default(1);
                $table->decimal('precio_unitario', 12, 2)->default(0);
                $table->decimal('total_linea', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ventas.pagos')) {
            Schema::create('ventas.pagos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('venta_id')->constrained('ventas.ventas')->restrictOnDelete();
                $table->foreignId('caja_id')->nullable()->constrained('ventas.cajas')->nullOnDelete();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('numero_comprobante', 60)->unique();
                $table->string('metodo_pago', 40)->default('EFECTIVO');
                $table->decimal('monto', 12, 2);
                $table->string('estado', 30)->default('CONFIRMADO');
                $table->timestamp('fecha_pago')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->string('referencia', 120)->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamps();
                $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->index(['venta_id', 'estado']);
            });
        }

        if (! Schema::hasTable('ventas.comprobantes')) {
            Schema::create('ventas.comprobantes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('venta_id')->constrained('ventas.ventas')->cascadeOnDelete();
                $table->foreignId('pago_id')->nullable()->constrained('ventas.pagos')->nullOnDelete();
                $table->string('tipo_comprobante', 40)->default('RECIBO');
                $table->string('numero', 60)->unique();
                $table->string('estado', 30)->default('BORRADOR');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('impuesto', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->timestamp('emitido_at')->nullable();
                $table->timestamps();
                $table->index(['venta_id', 'estado']);
            });
        }

        $this->sembrarCatalogos();
        $this->registrarMenu();
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $this->codigos)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $this->codigos)->delete();

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Ventas')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('ventas.comprobantes');
        Schema::dropIfExists('ventas.pagos');
        Schema::dropIfExists('ventas.venta_detalles');
        Schema::dropIfExists('ventas.ventas');
        Schema::dropIfExists('ventas.cajas');
    }

    private function sembrarCatalogos(): void
    {
        $now = Carbon::now();
        DB::table('ventas.cajas')->updateOrInsert(
            ['codigo' => 'CAJA-PRINCIPAL'],
            ['nombre' => 'Caja principal', 'descripcion' => 'Caja operativa inicial para ventas y pagos Revive.', 'saldo_inicial' => 0, 'activa' => true, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Ventas')->value('id_usermenu');
        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Ventas',
                'icono' => 'point_of_sale',
                'activo' => true,
                'orden' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'VENTAS-CAJAS' => ['nombre' => 'Cajas', 'accion' => '/cajas', 'clave_pagina' => 'CajasPage', 'icono' => 'point_of_sale', 'orden' => 1, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
            'VENTAS-VENTAS' => ['nombre' => 'Ventas', 'accion' => '/ventas', 'clave_pagina' => 'VentasPage', 'icono' => 'shopping_cart', 'orden' => 2, 'roles' => ['ADMINISTRADOR', 'CAJERO', 'RECEPCIONISTA']],
            'VENTAS-PAGOS' => ['nombre' => 'Pagos', 'accion' => '/pagos', 'clave_pagina' => 'PagosPage', 'icono' => 'payments', 'orden' => 3, 'roles' => ['ADMINISTRADOR', 'CAJERO', 'RECEPCIONISTA']],
            'VENTAS-COMPROBANTES' => ['nombre' => 'Comprobantes', 'accion' => '/comprobantes', 'clave_pagina' => 'ComprobantesPage', 'icono' => 'receipt_long', 'orden' => 4, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
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
