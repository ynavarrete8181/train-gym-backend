<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE IF EXISTS socios.socio_membresias
            ADD COLUMN IF NOT EXISTS sede_id BIGINT REFERENCES core.sedes(id)
        ");

        DB::statement("
            ALTER TABLE IF EXISTS socios.socio_membresias
            ADD COLUMN IF NOT EXISTS precio_aplicado NUMERIC(12, 2)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE IF EXISTS socios.socio_membresias DROP COLUMN IF EXISTS precio_aplicado");
        DB::statement("ALTER TABLE IF EXISTS socios.socio_membresias DROP COLUMN IF EXISTS sede_id");
    }
};
