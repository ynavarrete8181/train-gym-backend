<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institucional.sedes', fn (Blueprint $table) => $table->string('codigo', 200)->change());
        Schema::table('institucional.unidades', fn (Blueprint $table) => $table->string('codigo', 200)->change());
        Schema::table('institucional.carreras_areas', fn (Blueprint $table) => $table->string('codigo', 200)->change());
    }

    public function down(): void
    {
        Schema::table('institucional.sedes', fn (Blueprint $table) => $table->string('codigo', 30)->change());
        Schema::table('institucional.unidades', fn (Blueprint $table) => $table->string('codigo', 40)->change());
        Schema::table('institucional.carreras_areas', fn (Blueprint $table) => $table->string('codigo', 40)->change());
    }
};
