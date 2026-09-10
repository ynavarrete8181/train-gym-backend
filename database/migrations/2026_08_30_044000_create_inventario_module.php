<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $codigos = [
        'INVENTARIO-CATEGORIAS',
        'INVENTARIO-PROVEEDORES',
        'INVENTARIO-PRODUCTOS',
        'INVENTARIO-MOVIMIENTOS',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS inventario');

        if (! Schema::hasTable('inventario.categorias_producto')) {
            Schema::create('inventario.categorias_producto', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 120)->unique();
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventario.proveedores')) {
            Schema::create('inventario.proveedores', function (Blueprint $table): void {
                $table->id();
                $table->string('ruc', 20)->nullable()->unique();
                $table->string('nombre', 160);
                $table->string('telefono', 40)->nullable();
                $table->string('email', 160)->nullable();
                $table->text('direccion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventario.productos')) {
            Schema::create('inventario.productos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('categoria_id')->nullable()->constrained('inventario.categorias_producto')->nullOnDelete();
                $table->foreignId('proveedor_id')->nullable()->constrained('inventario.proveedores')->nullOnDelete();
                $table->string('codigo', 60)->unique();
                $table->string('nombre', 160);
                $table->text('descripcion')->nullable();
                $table->string('marca', 100)->nullable();
                $table->string('unidad_medida', 30)->default('UNIDAD');
                $table->decimal('precio_costo', 12, 2)->default(0);
                $table->decimal('precio_venta', 12, 2)->default(0);
                $table->decimal('stock_actual', 12, 2)->default(0);
                $table->decimal('stock_minimo', 12, 2)->default(0);
                $table->boolean('controla_stock')->default(true);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->index(['nombre', 'activo']);
            });
        }

        if (! Schema::hasTable('inventario.movimientos')) {
            Schema::create('inventario.movimientos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('producto_id')->constrained('inventario.productos')->restrictOnDelete();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('tipo_movimiento', 30);
                $table->decimal('cantidad', 12, 2);
                $table->decimal('stock_anterior', 12, 2)->default(0);
                $table->decimal('stock_nuevo', 12, 2)->default(0);
                $table->string('referencia', 120)->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamp('fecha_movimiento')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamps();
                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->nullOnDelete();
                $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->index(['producto_id', 'fecha_movimiento']);
                $table->index('tipo_movimiento');
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

        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Inventario')->value('id_usermenu');
        if ($menuId && ! DB::table('seguridad.cpu_userfunction')->where('id_usermenu', $menuId)->exists()) {
            DB::table('seguridad.cpu_usermenu')->where('id_usermenu', $menuId)->delete();
        }

        Schema::dropIfExists('inventario.movimientos');
        Schema::dropIfExists('inventario.productos');
        Schema::dropIfExists('inventario.proveedores');
        Schema::dropIfExists('inventario.categorias_producto');
    }

    private function sembrarCatalogos(): void
    {
        $now = Carbon::now();
        foreach (['Suplementos', 'Bebidas', 'Accesorios', 'Indumentaria', 'Servicios complementarios'] as $nombre) {
            DB::table('inventario.categorias_producto')->updateOrInsert(
                ['nombre' => $nombre],
                ['descripcion' => "Categoría {$nombre} para operación comercial Revive.", 'activo' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        DB::table('inventario.proveedores')->updateOrInsert(
            ['nombre' => 'Proveedor general Revive'],
            ['ruc' => null, 'telefono' => null, 'email' => null, 'direccion' => null, 'activo' => true, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    private function registrarMenu(): void
    {
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Inventario')->value('id_usermenu');
        if (! $menuId) {
            $menuId = DB::table('seguridad.cpu_usermenu')->insertGetId([
                'menu' => 'Inventario',
                'icono' => 'inventory_2',
                'activo' => true,
                'orden' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_usermenu');
        }

        $funciones = [
            'INVENTARIO-CATEGORIAS' => ['nombre' => 'Categorías', 'accion' => '/inventario-categorias', 'clave_pagina' => 'CategoriasInventarioPage', 'icono' => 'category', 'orden' => 1, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
            'INVENTARIO-PROVEEDORES' => ['nombre' => 'Proveedores', 'accion' => '/proveedores', 'clave_pagina' => 'ProveedoresPage', 'icono' => 'local_shipping', 'orden' => 2, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
            'INVENTARIO-PRODUCTOS' => ['nombre' => 'Productos', 'accion' => '/productos', 'clave_pagina' => 'ProductosPage', 'icono' => 'inventory', 'orden' => 3, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
            'INVENTARIO-MOVIMIENTOS' => ['nombre' => 'Movimientos / Kardex', 'accion' => '/movimientos-inventario', 'clave_pagina' => 'MovimientosInventarioPage', 'icono' => 'swap_horiz', 'orden' => 4, 'roles' => ['ADMINISTRADOR', 'CAJERO']],
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
