<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institucional.sedes_aliases', function (Blueprint $table) {
            $table->bigIncrements('id_sede_alias');
            $table->unsignedBigInteger('id_sede');
            $table->string('alias', 180);
            $table->string('alias_normalizado', 180)->unique();
            $table->timestamps();
            $table->foreign('id_sede')->references('id_sede')->on('institucional.sedes')->cascadeOnDelete();
        });

        Schema::create('institucional.unidades_aliases', function (Blueprint $table) {
            $table->bigIncrements('id_unidad_alias');
            $table->unsignedBigInteger('id_unidad');
            $table->string('alias', 180);
            $table->string('alias_normalizado', 180)->unique();
            $table->timestamps();
            $table->foreign('id_unidad')->references('id_unidad')->on('institucional.unidades')->cascadeOnDelete();
        });

        Schema::create('institucional.carreras_areas_aliases', function (Blueprint $table) {
            $table->bigIncrements('id_carrera_area_alias');
            $table->unsignedBigInteger('id_carrera_area');
            $table->string('alias', 180);
            $table->string('alias_normalizado', 180)->unique();
            $table->timestamps();
            $table->foreign('id_carrera_area')->references('id_carrera_area')->on('institucional.carreras_areas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institucional.carreras_areas_aliases');
        Schema::dropIfExists('institucional.unidades_aliases');
        Schema::dropIfExists('institucional.sedes_aliases');
    }
};
