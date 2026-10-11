<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguridad.cargas_masivas_usuario', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('estado', 40)->default('EN_COLA')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('creados')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->unsignedInteger('notificaciones_encoladas')->default(0);
            $table->unsignedInteger('notificaciones_error')->default(0);
            $table->boolean('notificar')->default(false);
            $table->unsignedBigInteger('solicitado_por')->nullable();
            $table->timestampTz('iniciado_at')->nullable();
            $table->timestampTz('finalizado_at')->nullable();
            $table->text('error_general')->nullable();
            $table->timestampsTz();
        });

        Schema::create('seguridad.carga_masiva_usuario_detalles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('carga_id')->index();
            $table->unsignedInteger('fila');
            $table->jsonb('datos');
            $table->string('estado', 30)->default('PENDIENTE')->index();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('estado_notificacion', 30)->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->foreign('carga_id')->references('id')->on('seguridad.cargas_masivas_usuario')->cascadeOnDelete();
            $table->unique(['carga_id', 'fila']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguridad.carga_masiva_usuario_detalles');
        Schema::dropIfExists('seguridad.cargas_masivas_usuario');
    }
};
