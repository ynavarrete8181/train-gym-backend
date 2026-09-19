<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventario.productos', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.productos', 'imagen_url')) {
                $table->text('imagen_url')->nullable();
            }
            if (! Schema::hasColumn('inventario.productos', 'maneja_lotes')) {
                $table->boolean('maneja_lotes')->default(false);
            }
        });

        if (! Schema::hasTable('inventario.producto_precios_sede')) {
            Schema::create('inventario.producto_precios_sede', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('producto_id')->constrained('inventario.productos')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->decimal('precio', 12, 2);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->cascadeOnDelete();
                $table->unique(['producto_id', 'sede_id']);
                $table->index(['sede_id', 'activo']);
            });
        }

        if (! Schema::hasTable('inventario.producto_stock_sede')) {
            Schema::create('inventario.producto_stock_sede', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('producto_id')->constrained('inventario.productos')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->decimal('stock_actual', 12, 2)->default(0);
                $table->decimal('stock_minimo', 12, 2)->default(0);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->cascadeOnDelete();
                $table->unique(['producto_id', 'sede_id']);
                $table->index('sede_id');
            });
        }

        if (! Schema::hasTable('inventario.lotes_producto')) {
            Schema::create('inventario.lotes_producto', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('producto_id')->constrained('inventario.productos')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->string('codigo_lote', 80);
                $table->date('fecha_vencimiento')->nullable();
                $table->decimal('cantidad_inicial', 12, 2)->default(0);
                $table->decimal('stock_actual', 12, 2)->default(0);
                $table->decimal('costo_unitario', 12, 2)->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->cascadeOnDelete();
                $table->unique(['producto_id', 'sede_id', 'codigo_lote']);
                $table->index(['sede_id', 'fecha_vencimiento']);
            });
        }

        Schema::table('inventario.movimientos', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.movimientos', 'lote_id')) {
                $table->foreignId('lote_id')
                    ->nullable()
                    ->after('producto_id')
                    ->constrained('inventario.lotes_producto')
                    ->nullOnDelete();
            }
        });

        $this->inicializarPorSede();
    }

    public function down(): void
    {
        Schema::table('inventario.movimientos', function (Blueprint $table): void {
            if (Schema::hasColumn('inventario.movimientos', 'lote_id')) {
                $table->dropConstrainedForeignId('lote_id');
            }
        });

        Schema::dropIfExists('inventario.lotes_producto');
        Schema::dropIfExists('inventario.producto_stock_sede');
        Schema::dropIfExists('inventario.producto_precios_sede');

        Schema::table('inventario.productos', function (Blueprint $table): void {
            $columnas = [];
            if (Schema::hasColumn('inventario.productos', 'imagen_url')) $columnas[] = 'imagen_url';
            if (Schema::hasColumn('inventario.productos', 'maneja_lotes')) $columnas[] = 'maneja_lotes';
            if ($columnas) $table->dropColumn($columnas);
        });
    }

    private function inicializarPorSede(): void
    {
        $sedes = DB::table('institucional.sedes')
            ->where('activo', true)
            ->where('maneja_inventario', true)
            ->pluck('id_sede');

        $productos = DB::table('inventario.productos')
            ->where('activo', true)
            ->get(['id', 'precio_venta', 'stock_minimo']);

        $stockInicial = app()->environment('production') ? 0 : 20;
        $ahora = now();

        foreach ($productos as $producto) {
            foreach ($sedes as $sedeId) {
                DB::table('inventario.producto_precios_sede')->updateOrInsert(
                    ['producto_id' => $producto->id, 'sede_id' => $sedeId],
                    ['precio' => $producto->precio_venta, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]
                );

                DB::table('inventario.producto_stock_sede')->updateOrInsert(
                    ['producto_id' => $producto->id, 'sede_id' => $sedeId],
                    ['stock_actual' => $stockInicial, 'stock_minimo' => $producto->stock_minimo, 'created_at' => $ahora, 'updated_at' => $ahora]
                );
            }
        }
    }
};
