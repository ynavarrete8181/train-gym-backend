<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS reservas.cupos_diarios (
                id BIGSERIAL PRIMARY KEY,
                horario_id BIGINT NOT NULL REFERENCES train_gimnasio.horarios_gym(id) ON DELETE CASCADE,
                sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
                servicio_id BIGINT NOT NULL,
                fecha DATE NOT NULL,
                hora_inicio TIME NOT NULL,
                hora_fin TIME NOT NULL,
                capacidad INTEGER NOT NULL DEFAULT 1,
                estado VARCHAR(30) NOT NULL DEFAULT 'ABIERTO',
                metadata JSONB,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                CONSTRAINT uq_reserva_cupo_diario UNIQUE (horario_id, fecha, hora_inicio, hora_fin),
                CONSTRAINT chk_cupos_diarios_horas CHECK (hora_fin > hora_inicio)
            )
        ");

        DB::statement("
            ALTER TABLE reservas.reservas
                ADD COLUMN IF NOT EXISTS cupo_diario_id BIGINT REFERENCES reservas.cupos_diarios(id) ON DELETE SET NULL
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_cupos_diarios_fecha_sede ON reservas.cupos_diarios (fecha, sede_id, hora_inicio)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cupos_diarios_servicio_fecha ON reservas.cupos_diarios (servicio_id, fecha)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reservas_cupo_estado ON reservas.reservas (cupo_diario_id, estado)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reservas.idx_reservas_cupo_estado');
        DB::statement('DROP INDEX IF EXISTS reservas.idx_cupos_diarios_servicio_fecha');
        DB::statement('DROP INDEX IF EXISTS reservas.idx_cupos_diarios_fecha_sede');
        DB::statement('ALTER TABLE reservas.reservas DROP COLUMN IF EXISTS cupo_diario_id');
        DB::statement('DROP TABLE IF EXISTS reservas.cupos_diarios');
    }
};
