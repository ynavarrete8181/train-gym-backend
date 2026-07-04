<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS socios.membresia_precios_sede (
                id BIGSERIAL PRIMARY KEY,
                membresia_id BIGINT NOT NULL REFERENCES socios.membresias(id) ON DELETE CASCADE,
                sede_id BIGINT NOT NULL REFERENCES core.sedes(id) ON DELETE CASCADE,
                precio NUMERIC(12, 2) NOT NULL CHECK (precio >= 0),
                vigencia_inicio DATE DEFAULT CURRENT_DATE,
                vigencia_fin DATE NULL,
                activa BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_membresia_precios_sede_lookup
            ON socios.membresia_precios_sede (membresia_id, sede_id, activa)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS socios.membresia_precios_sede");
    }
};
