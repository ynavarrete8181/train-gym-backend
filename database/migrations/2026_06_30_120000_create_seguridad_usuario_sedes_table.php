<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS seguridad.usuario_sedes (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES seguridad.usuarios(id) ON DELETE CASCADE,
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id) ON DELETE CASCADE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_seguridad_usuario_sede UNIQUE (usuario_id, sede_id)
);

CREATE INDEX IF NOT EXISTS idx_seguridad_usuario_sedes_usuario
    ON seguridad.usuario_sedes (usuario_id, activo);

INSERT INTO seguridad.usuario_sedes (usuario_id, sede_id, activo, created_at, updated_at)
SELECT u.id, s.id, TRUE, NOW(), NOW()
FROM seguridad.usuarios u
CROSS JOIN core.sedes s
WHERE s.activa = TRUE
AND NOT EXISTS (
    SELECT 1
    FROM seguridad.usuario_sedes us
    WHERE us.usuario_id = u.id
    AND us.sede_id = s.id
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_seguridad_usuario_sedes_usuario;
DROP TABLE IF EXISTS seguridad.usuario_sedes;
SQL);
    }
};
