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
        Schema::table('entrenamiento.evaluaciones', function (Blueprint $table) {
            $table->date('fecha_proxima_evaluacion')->nullable();
        });

        Schema::table('entrenamiento.rm_registros', function (Blueprint $table) {
            $table->date('fecha_proximo_control')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entrenamiento.evaluaciones', function (Blueprint $table) {
            $table->dropColumn('fecha_proxima_evaluacion');
        });

        Schema::table('entrenamiento.rm_registros', function (Blueprint $table) {
            $table->dropColumn('fecha_proximo_control');
        });
    }
};
