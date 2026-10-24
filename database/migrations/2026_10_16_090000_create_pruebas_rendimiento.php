<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entrenamiento.pruebas_rendimiento', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('deportista_id');
            $table->unsignedBigInteger('registrado_por');
            $table->date('fecha_prueba');
            $table->string('codigo_prueba', 40);
            $table->decimal('resultado', 12, 3);
            $table->string('unidad', 20);
            $table->string('protocolo', 500);
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('ACTIVO');
            $table->timestamps();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
            $table->foreign('registrado_por')->references('id')->on('seguridad.users')->restrictOnDelete();
            $table->index(['deportista_id', 'codigo_prueba', 'fecha_prueba'], 'pruebas_rendimiento_historial_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('entrenamiento.pruebas_rendimiento');
    }
};
