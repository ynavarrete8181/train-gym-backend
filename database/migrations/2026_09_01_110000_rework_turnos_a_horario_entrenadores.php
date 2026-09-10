<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gimnasio.asignaciones_entrenador_cliente') && Schema::hasColumn('gimnasio.asignaciones_entrenador_cliente', 'turno_id')) {
            Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->dropForeign(['turno_id']);
            });

            DB::statement('DROP INDEX IF EXISTS gimnasio.uq_asignacion_activa_por_turno');

            Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->renameColumn('turno_id', 'horario_bloque_id');
            });

            Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->foreign('horario_bloque_id')->references('id')->on('gimnasio.horario_bloques')->nullOnDelete();
            });

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_asignacion_activa_por_horario ON gimnasio.asignaciones_entrenador_cliente (deportista_id, horario_bloque_id) WHERE estado = 'ACTIVO' AND horario_bloque_id IS NOT NULL");
        }

        if (! Schema::hasTable('gimnasio.horario_entrenadores')) {
            Schema::create('gimnasio.horario_entrenadores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entrenador_id')->constrained('gimnasio.entrenadores')->cascadeOnDelete();
                $table->foreignId('horario_bloque_id')->constrained('gimnasio.horario_bloques')->cascadeOnDelete();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['entrenador_id', 'horario_bloque_id']);
                $table->index(['horario_bloque_id', 'activo']);
            });
        }

        Schema::dropIfExists('gimnasio.turnos_entrenador');
    }

    public function down(): void
    {
        if (! Schema::hasTable('gimnasio.turnos_entrenador')) {
            Schema::create('gimnasio.turnos_entrenador', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entrenador_id')->constrained('gimnasio.entrenadores')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->string('dia_semana', 15);
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->unsignedSmallInteger('capacidad')->default(1);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
                $table->index(['entrenador_id', 'dia_semana']);
            });
        }

        Schema::dropIfExists('gimnasio.horario_entrenadores');

        if (Schema::hasTable('gimnasio.asignaciones_entrenador_cliente') && Schema::hasColumn('gimnasio.asignaciones_entrenador_cliente', 'horario_bloque_id')) {
            Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->dropForeign(['horario_bloque_id']);
            });

            DB::statement('DROP INDEX IF EXISTS gimnasio.uq_asignacion_activa_por_horario');

            Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->renameColumn('horario_bloque_id', 'turno_id');
            });

            Schema::table('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->foreign('turno_id')->references('id')->on('gimnasio.turnos_entrenador')->nullOnDelete();
            });

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_asignacion_activa_por_turno ON gimnasio.asignaciones_entrenador_cliente (deportista_id, turno_id) WHERE estado = 'ACTIVO' AND turno_id IS NOT NULL");
        }
    }
};
