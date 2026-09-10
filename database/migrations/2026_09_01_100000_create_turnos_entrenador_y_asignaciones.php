<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS gimnasio');

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

        if (! Schema::hasTable('gimnasio.asignaciones_entrenador_cliente')) {
            Schema::create('gimnasio.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entrenador_id')->constrained('gimnasio.entrenadores')->cascadeOnDelete();
                $table->foreignId('deportista_id')->constrained('gimnasio.deportistas')->cascadeOnDelete();
                $table->foreignId('turno_id')->nullable()->constrained('gimnasio.turnos_entrenador')->nullOnDelete();
                $table->foreignId('membresia_id')->nullable()->constrained('gimnasio.membresias')->nullOnDelete();
                $table->string('tipo_asignacion', 30)->default('SEGUIMIENTO');
                $table->date('fecha_inicio');
                $table->date('fecha_fin')->nullable();
                $table->string('estado', 20)->default('ACTIVO');
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->index(['entrenador_id', 'estado']);
                $table->index(['deportista_id', 'estado']);
                $table->index(['turno_id', 'estado']);
            });
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_asignacion_activa_por_turno ON gimnasio.asignaciones_entrenador_cliente (deportista_id, turno_id) WHERE estado = 'ACTIVO' AND turno_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('gimnasio.asignaciones_entrenador_cliente');
        Schema::dropIfExists('gimnasio.turnos_entrenador');
    }
};
