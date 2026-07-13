<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('socios.membresias', function (Blueprint $table) {
            $table->boolean('facturacion_automatica')->default(true)->after('activa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('socios.membresias', function (Blueprint $table) {
            $table->dropColumn('facturacion_automatica');
        });
    }
};
