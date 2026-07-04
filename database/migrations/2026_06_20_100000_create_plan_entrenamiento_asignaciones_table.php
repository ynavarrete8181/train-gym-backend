<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS entrenamiento.plan_asignaciones (
                id BIGSERIAL PRIMARY KEY,
                plan_id BIGINT NOT NULL REFERENCES entrenamiento.planes(id) ON DELETE CASCADE,
                alcance VARCHAR(20) NOT NULL DEFAULT 'GRUPAL',
                persona_id BIGINT NULL REFERENCES core.personas(id) ON DELETE SET NULL,
                nombre_grupo VARCHAR(120) NULL,
                fecha_inicio DATE NULL,
                fecha_fin DATE NULL,
                estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
                observaciones TEXT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS entrenamiento.plan_asignaciones');
    }
};
