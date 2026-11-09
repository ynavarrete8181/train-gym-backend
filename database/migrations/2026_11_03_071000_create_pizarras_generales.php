<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrenamiento.pizarras_generales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sede_id');
            $table->date('fecha');
            $table->string('nombre', 150);
            $table->string('objetivo', 250)->nullable();
            $table->string('estado', 20)->default('ACTIVA');
            $table->timestamps();
            $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
            $table->unique(['sede_id', 'fecha']);
        });

        Schema::create('entrenamiento.pizarra_general_ejercicios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pizarra_id')->constrained('entrenamiento.pizarras_generales')->cascadeOnDelete();
            $table->foreignId('ejercicio_id')->constrained('entrenamiento.ejercicios')->restrictOnDelete();
            $table->string('bloque', 80)->nullable();
            $table->unsignedSmallInteger('orden');
            $table->unsignedSmallInteger('series')->default(1);
            $table->string('repeticiones', 50)->nullable();
            $table->unsignedSmallInteger('tiempo_segundos')->nullable();
            $table->unsignedSmallInteger('descanso_segundos')->nullable();
            $table->text('indicaciones')->nullable();
            $table->timestamps();
            $table->unique(['pizarra_id', 'ejercicio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrenamiento.pizarra_general_ejercicios');
        Schema::dropIfExists('entrenamiento.pizarras_generales');
    }
};
