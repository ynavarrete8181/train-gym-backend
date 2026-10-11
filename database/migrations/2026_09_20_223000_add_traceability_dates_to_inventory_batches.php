<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventario.lotes_producto', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.lotes_producto', 'fecha_elaboracion')) {
                $table->date('fecha_elaboracion')->nullable()->after('codigo_lote');
            }

            if (! Schema::hasColumn('inventario.lotes_producto', 'fecha_ingreso_inventario')) {
                $table->date('fecha_ingreso_inventario')->nullable()->after('fecha_elaboracion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventario.lotes_producto', function (Blueprint $table): void {
            $columnas = [];
            if (Schema::hasColumn('inventario.lotes_producto', 'fecha_ingreso_inventario')) $columnas[] = 'fecha_ingreso_inventario';
            if (Schema::hasColumn('inventario.lotes_producto', 'fecha_elaboracion')) $columnas[] = 'fecha_elaboracion';
            if ($columnas) $table->dropColumn($columnas);
        });
    }
};
