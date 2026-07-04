<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS entrenamiento;

CREATE TABLE IF NOT EXISTS entrenamiento.ejercicios (
    id BIGSERIAL PRIMARY KEY,
    gimnasio_id BIGINT,
    nombre VARCHAR(150) NOT NULL,
    grupo_muscular VARCHAR(50) NOT NULL,
    equipamiento VARCHAR(50) NOT NULL,
    instrucciones TEXT,
    url_recurso TEXT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
SQL
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS entrenamiento.ejercicios;
SQL
        );
    }
};
