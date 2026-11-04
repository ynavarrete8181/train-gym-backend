<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrenamiento.rutina_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rutina_id')->constrained('entrenamiento.rutinas')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero_serie');
            $table->string('tipo_carga', 30)->default('LIBRE');
            $table->decimal('porcentaje_rm', 5, 2)->nullable();
            $table->decimal('carga_fija', 10, 2)->nullable();
            $table->string('unidad_carga', 10)->default('KG');
            $table->string('repeticiones', 50)->nullable();
            $table->unsignedSmallInteger('descanso_segundos')->nullable();
            $table->decimal('rpe', 4, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['rutina_id', 'numero_serie']);
        });

        Schema::create('entrenamiento.rutina_transferencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rutina_id')->constrained('entrenamiento.rutinas')->cascadeOnDelete();
            $table->foreignId('ejercicio_id')->constrained('entrenamiento.ejercicios')->restrictOnDelete();
            $table->unsignedSmallInteger('orden')->default(1);
            $table->string('modo_aplicacion', 30)->default('DESPUES_DE_SERIE');
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['rutina_id', 'ejercicio_id']);
        });

        Schema::create('entrenamiento.rutina_transferencia_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transferencia_id')->constrained('entrenamiento.rutina_transferencias')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero_serie');
            $table->string('repeticiones', 50)->nullable();
            $table->unsignedSmallInteger('tiempo_segundos')->nullable();
            $table->decimal('distancia_metros', 8, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['transferencia_id', 'numero_serie']);
        });

        DB::statement("INSERT INTO entrenamiento.rutina_series
            (rutina_id, numero_serie, tipo_carga, porcentaje_rm, carga_fija, unidad_carga, repeticiones, descanso_segundos, created_at, updated_at)
            SELECT r.id, s.numero_serie, r.tipo_carga,
                CASE WHEN r.tipo_carga = 'PORCENTAJE_RM' THEN r.carga_objetivo END,
                CASE WHEN r.tipo_carga = 'KG' THEN r.carga_objetivo END,
                'KG', r.repeticiones, r.descanso_segundos, NOW(), NOW()
            FROM entrenamiento.rutinas r
            CROSS JOIN LATERAL generate_series(1, r.series) AS s(numero_serie)");
    }

    public function down(): void
    {
        Schema::dropIfExists('entrenamiento.rutina_transferencia_series');
        Schema::dropIfExists('entrenamiento.rutina_transferencias');
        Schema::dropIfExists('entrenamiento.rutina_series');
    }
};
