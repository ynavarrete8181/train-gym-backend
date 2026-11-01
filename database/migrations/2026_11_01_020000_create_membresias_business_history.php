<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('membresias.plan_modalidad_versiones')) {
            Schema::create('membresias.plan_modalidad_versiones', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('modalidad_id');
                $table->unsignedBigInteger('plan_id');
                $table->unsignedInteger('version');
                $table->string('accion', 24);
                $table->jsonb('condiciones_anteriores')->nullable();
                $table->jsonb('condiciones_nuevas');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->timestamp('registrado_at')->useCurrent();
                $table->foreign('modalidad_id')->references('id')->on('membresias.plan_modalidades')->restrictOnDelete();
                $table->foreign('plan_id')->references('id')->on('membresias.planes')->restrictOnDelete();
                $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->unique(['modalidad_id', 'version'], 'modalidad_version_unica');
                $table->index(['plan_id', 'registrado_at'], 'modalidad_version_plan_fecha_idx');
            });
        }

        if (! Schema::hasTable('membresias.membresia_condiciones_historial')) {
            Schema::create('membresias.membresia_condiciones_historial', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('membresia_id');
                $table->unsignedInteger('version');
                $table->string('accion', 40);
                $table->jsonb('condiciones');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->text('motivo')->nullable();
                $table->timestamp('registrado_at')->useCurrent();
                $table->foreign('membresia_id')->references('id')->on('membresias.membresias')->restrictOnDelete();
                $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->unique(['membresia_id', 'version'], 'membresia_condiciones_version_unica');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membresias.membresia_condiciones_historial');
        Schema::dropIfExists('membresias.plan_modalidad_versiones');
    }
};
