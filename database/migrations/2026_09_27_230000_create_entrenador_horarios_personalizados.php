<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gimnasio.entrenador_horarios', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('entrenador_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->foreign('entrenador_id')->references('id')->on('gimnasio.entrenadores')->cascadeOnDelete();
            $table->index(['entrenador_id', 'activo']);
            $table->index(['fecha_inicio', 'fecha_fin']);
        });

        Schema::create('gimnasio.entrenador_horario_franjas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('entrenador_horario_id');
            $table->unsignedBigInteger('sede_id');
            $table->string('dia_semana', 15);
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign('entrenador_horario_id')->references('id')->on('gimnasio.entrenador_horarios')->cascadeOnDelete();
            $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes');
            $table->index(['entrenador_horario_id', 'dia_semana']);
        });

        Schema::create('gimnasio.entrenador_horario_recesos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('entrenador_horario_id');
            $table->string('dia_semana', 15);
            $table->string('tipo', 30)->default('PAUSA');
            $table->string('descripcion', 150)->nullable();
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign('entrenador_horario_id')->references('id')->on('gimnasio.entrenador_horarios')->cascadeOnDelete();
            $table->index(['entrenador_horario_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gimnasio.entrenador_horario_recesos');
        Schema::dropIfExists('gimnasio.entrenador_horario_franjas');
        Schema::dropIfExists('gimnasio.entrenador_horarios');
    }
};
