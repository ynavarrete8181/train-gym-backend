<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS staff.cliente_asignaciones (
    id BIGSERIAL PRIMARY KEY,
    coach_id BIGINT NOT NULL REFERENCES staff.perfiles(id) ON DELETE CASCADE,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    socio_id BIGINT REFERENCES socios.socios(id) ON DELETE SET NULL,
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
    turno_recurrente_id BIGINT REFERENCES staff.turnos_recurrentes(id) ON DELETE SET NULL,
    tipo_asignacion VARCHAR(30) NOT NULL DEFAULT 'SEGUIMIENTO',
    fecha_inicio DATE NOT NULL DEFAULT CURRENT_DATE,
    fecha_fin DATE,
    estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
    objetivo TEXT,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_staff_cliente_asignacion_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_cliente_asignacion_activa
    ON staff.cliente_asignaciones (persona_id)
    WHERE estado = 'ACTIVO';

CREATE INDEX IF NOT EXISTS idx_staff_cliente_asignaciones_coach
    ON staff.cliente_asignaciones (coach_id, estado);

CREATE INDEX IF NOT EXISTS idx_staff_cliente_asignaciones_turno
    ON staff.cliente_asignaciones (turno_recurrente_id, estado);

CREATE INDEX IF NOT EXISTS idx_staff_cliente_asignaciones_sede
    ON staff.cliente_asignaciones (sede_id, estado);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS staff.idx_staff_cliente_asignaciones_sede;
DROP INDEX IF EXISTS staff.idx_staff_cliente_asignaciones_turno;
DROP INDEX IF EXISTS staff.idx_staff_cliente_asignaciones_coach;
DROP INDEX IF EXISTS staff.uq_staff_cliente_asignacion_activa;
DROP TABLE IF EXISTS staff.cliente_asignaciones;
SQL);
    }
};
