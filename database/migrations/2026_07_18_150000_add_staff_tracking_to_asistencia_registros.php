<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE asistencia.registros
                ADD COLUMN IF NOT EXISTS coach_id BIGINT REFERENCES staff.perfiles(id),
                ADD COLUMN IF NOT EXISTS staff_cliente_asignacion_id BIGINT REFERENCES staff.cliente_asignaciones(id),
                ADD COLUMN IF NOT EXISTS turno_recurrente_id BIGINT REFERENCES staff.turnos_recurrentes(id)
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_asistencia_coach_fecha ON asistencia.registros (coach_id, fecha_hora DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_asistencia_staff_asignacion ON asistencia.registros (staff_cliente_asignacion_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS asistencia.idx_asistencia_staff_asignacion');
        DB::statement('DROP INDEX IF EXISTS asistencia.idx_asistencia_coach_fecha');

        DB::statement("
            ALTER TABLE asistencia.registros
                DROP COLUMN IF EXISTS turno_recurrente_id,
                DROP COLUMN IF EXISTS staff_cliente_asignacion_id,
                DROP COLUMN IF EXISTS coach_id
        ");
    }
};
