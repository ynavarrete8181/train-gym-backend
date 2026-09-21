<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas.ventas', function (Blueprint $table): void {
            if (! Schema::hasColumn('ventas.ventas', 'inventario_aplicado_at')) {
                $table->timestamp('inventario_aplicado_at')->nullable()->after('fecha_venta');
            }
        });

        Schema::table('ventas.venta_detalles', function (Blueprint $table): void {
            if (! Schema::hasColumn('ventas.venta_detalles', 'tipo_item')) {
                $table->string('tipo_item', 30)->nullable()->after('producto_id');
            }
            if (! Schema::hasColumn('ventas.venta_detalles', 'referencia_id')) {
                $table->unsignedBigInteger('referencia_id')->nullable()->after('tipo_item');
            }
        });

        Schema::table('inventario.movimientos', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.movimientos', 'venta_id')) {
                $table->unsignedBigInteger('venta_id')->nullable()->after('lote_id');
                $table->foreign('venta_id')->references('id')->on('ventas.ventas')->nullOnDelete();
                $table->index('venta_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventario.movimientos', function (Blueprint $table): void {
            if (Schema::hasColumn('inventario.movimientos', 'venta_id')) {
                $table->dropForeign(['venta_id']);
                $table->dropIndex(['venta_id']);
                $table->dropColumn('venta_id');
            }
        });

        Schema::table('ventas.venta_detalles', function (Blueprint $table): void {
            $columnas = [];
            if (Schema::hasColumn('ventas.venta_detalles', 'referencia_id')) $columnas[] = 'referencia_id';
            if (Schema::hasColumn('ventas.venta_detalles', 'tipo_item')) $columnas[] = 'tipo_item';
            if ($columnas) $table->dropColumn($columnas);
        });

        Schema::table('ventas.ventas', function (Blueprint $table): void {
            if (Schema::hasColumn('ventas.ventas', 'inventario_aplicado_at')) {
                $table->dropColumn('inventario_aplicado_at');
            }
        });
    }
};
