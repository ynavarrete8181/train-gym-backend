<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mismo criterio que created_at: fecha y hora en la zona de la aplicación.
        DB::statement('ALTER TABLE clientes.deportista_representantes
            ALTER COLUMN fecha_inicio TYPE timestamp(0) USING COALESCE(created_at, fecha_inicio::timestamp),
            ALTER COLUMN fecha_fin TYPE timestamp(0) USING CASE WHEN activo THEN NULL ELSE COALESCE(updated_at, fecha_fin::timestamp) END');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clientes.deportista_representantes
            ALTER COLUMN fecha_inicio TYPE date USING fecha_inicio::date,
            ALTER COLUMN fecha_fin TYPE date USING fecha_fin::date');
    }
};
