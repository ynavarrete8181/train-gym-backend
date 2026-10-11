<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seguridad.cargas_masivas_usuario', function (Blueprint $table): void {
            $table->unsignedInteger('notificaciones_enviadas')->default(0)->after('notificaciones_encoladas');
        });

        Schema::table('seguridad.carga_masiva_usuario_detalles', function (Blueprint $table): void {
            $table->unsignedBigInteger('notificacion_id')->nullable()->after('usuario_id');
            $table->foreign('notificacion_id')->references('id')->on('notificaciones.notificaciones')->nullOnDelete();
        });

        Schema::create('seguridad.avisos_usuario', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('usuario_id')->index();
            $table->string('tipo', 50)->index();
            $table->string('titulo', 180);
            $table->text('mensaje')->nullable();
            $table->string('vista', 100)->nullable();
            $table->string('referencia_tipo', 60)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestampTz('leido_at')->nullable();
            $table->timestampsTz();
            $table->foreign('usuario_id')->references('id')->on('seguridad.users')->cascadeOnDelete();
            $table->index(['usuario_id', 'leido_at']);
        });

        Schema::create('notificaciones.lotes_acceso', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('estado', 40)->default('EN_COLA')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesados')->default(0);
            $table->unsignedInteger('enviadas')->default(0);
            $table->unsignedInteger('en_cola')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->boolean('forzar')->default(false);
            $table->unsignedBigInteger('solicitado_por')->nullable()->index();
            $table->timestampTz('iniciado_at')->nullable();
            $table->timestampTz('finalizado_at')->nullable();
            $table->text('error_general')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notificaciones.lote_acceso_detalles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lote_id')->index();
            $table->unsignedBigInteger('usuario_id')->index();
            $table->string('estado', 30)->default('PENDIENTE')->index();
            $table->unsignedBigInteger('notificacion_id')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->foreign('lote_id')->references('id')->on('notificaciones.lotes_acceso')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('seguridad.users')->cascadeOnDelete();
            $table->unique(['lote_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones.lote_acceso_detalles');
        Schema::dropIfExists('notificaciones.lotes_acceso');
        Schema::dropIfExists('seguridad.avisos_usuario');

        Schema::table('seguridad.carga_masiva_usuario_detalles', function (Blueprint $table): void {
            $table->dropForeign(['notificacion_id']);
            $table->dropColumn('notificacion_id');
        });

        Schema::table('seguridad.cargas_masivas_usuario', function (Blueprint $table): void {
            $table->dropColumn('notificaciones_enviadas');
        });
    }
};
