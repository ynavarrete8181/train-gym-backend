<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes.valoracion_registros', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('deportista_id');
            $table->unsignedBigInteger('registrado_por');
            $table->string('seccion', 30);
            $table->date('fecha_registro');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->date('proximo_control')->nullable();
            $table->string('titulo', 180);
            $table->string('estado', 25)->default('ACTIVO');
            $table->jsonb('datos')->default('{}');
            $table->timestamps();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
            $table->foreign('registrado_por')->references('id')->on('seguridad.users');
            $table->index(['deportista_id','seccion','fecha_registro'], 'valoracion_registros_seccion_idx');
            $table->index(['proximo_control','estado'], 'valoracion_registros_control_idx');
        });
        Schema::create('clientes.valoracion_controles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('registro_origen_id');
            $table->unsignedBigInteger('deportista_id');
            $table->unsignedBigInteger('responsable_id')->nullable();
            $table->date('fecha_programada');
            $table->string('estado', 25)->default('PROGRAMADO');
            $table->unsignedBigInteger('registro_resultado_id')->nullable();
            $table->timestamp('realizado_at')->nullable();
            $table->timestamps();
            $table->foreign('registro_origen_id')->references('id')->on('clientes.valoracion_registros')->restrictOnDelete();
            $table->foreign('registro_resultado_id')->references('id')->on('clientes.valoracion_registros')->nullOnDelete();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
            $table->foreign('responsable_id')->references('id')->on('seguridad.users')->nullOnDelete();
            $table->index(['fecha_programada','estado'], 'valoracion_controles_vencimiento_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('clientes.valoracion_controles');
        Schema::dropIfExists('clientes.valoracion_registros');
    }
};
