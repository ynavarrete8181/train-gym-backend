<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventario.productos', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventario.productos', 'imagen_path')) {
                $table->string('imagen_path', 500)->nullable()->after('imagen_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventario.productos', function (Blueprint $table): void {
            if (Schema::hasColumn('inventario.productos', 'imagen_path')) {
                $table->dropColumn('imagen_path');
            }
        });
    }
};
