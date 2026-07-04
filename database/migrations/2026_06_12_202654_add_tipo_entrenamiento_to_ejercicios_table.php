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
        Schema::table('entrenamiento.ejercicios', function (Blueprint $table) {
            $table->string('tipo_entrenamiento', 50)->nullable()->default('GENERAL');
        });
    }

    public function down(): void
    {
        Schema::table('entrenamiento.ejercicios', function (Blueprint $table) {
            $table->dropColumn('tipo_entrenamiento');
        });
    }
};
