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
    }

    public function down(): void
    {
        Schema::table('ventas.venta_detalles', function (Blueprint $table): void {
            if (Schema::hasColumn('ventas.venta_detalles', 'referencia_id')) $table->dropColumn('referencia_id');
            if (Schema::hasColumn('ventas.venta_detalles', 'tipo_item')) $table->dropColumn('tipo_item');
        });

        Schema::table('ventas.ventas', function (Blueprint $table): void {
            if (Schema::hasColumn('ventas.ventas', 'inventario_aplicado_at')) $table->dropColumn('inventario_aplicado_at');
        });
    }
};
