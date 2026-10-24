<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('entrenamiento.ejercicios', function (Blueprint $table): void {
            $table->string('video_path', 500)->nullable();
        });
    }
    public function down(): void {
        Schema::table('entrenamiento.ejercicios', function (Blueprint $table): void {
            $table->dropColumn('video_path');
        });
    }
};
