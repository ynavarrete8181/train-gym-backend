<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('entrenamiento.asignaciones_entrenador_cliente', 'entrenador_horario_id')) {
            Schema::table('entrenamiento.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->unsignedBigInteger('entrenador_horario_id')->nullable()->after('horario_bloque_id');
                $table->foreign('entrenador_horario_id')
                    ->references('id')
                    ->on('agenda.entrenador_horarios')
                    ->nullOnDelete();
                $table->index(['entrenador_horario_id', 'estado'], 'idx_asig_cliente_horario_vigente');
            });
        }

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_asignacion_activa_horario_vigente
             ON entrenamiento.asignaciones_entrenador_cliente
             (deportista_id, entrenador_id, entrenador_horario_id)
             WHERE estado = 'ACTIVO' AND entrenador_horario_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entrenamiento.uq_asignacion_activa_horario_vigente');

        if (Schema::hasColumn('entrenamiento.asignaciones_entrenador_cliente', 'entrenador_horario_id')) {
            Schema::table('entrenamiento.asignaciones_entrenador_cliente', function (Blueprint $table): void {
                $table->dropForeign(['entrenador_horario_id']);
                $table->dropIndex('idx_asig_cliente_horario_vigente');
                $table->dropColumn('entrenador_horario_id');
            });
        }
    }
};
