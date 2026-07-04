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
            $table->string('nivel_resultado', 30)->nullable()->default('MEDIO');
        });
    }

    public function down(): void
    {
        Schema::table('entrenamiento.evaluaciones', function (Blueprint $table) {
            $table->dropColumn('nivel_resultado');
        });
    }
};
