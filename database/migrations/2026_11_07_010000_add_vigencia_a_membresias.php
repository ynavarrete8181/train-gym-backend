<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vigencia de la asignación del Pase Diario. No cambia con cada venta, a diferencia
 * de fecha_inicio y fecha_fin, que guardan el día de uso. Sin fin, el pase no tiene límite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE membresias.membresias ADD COLUMN IF NOT EXISTS vigencia_inicio date NULL');
        DB::statement('ALTER TABLE membresias.membresias ADD COLUMN IF NOT EXISTS vigencia_fin date NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE membresias.membresias DROP COLUMN IF EXISTS vigencia_inicio');
        DB::statement('ALTER TABLE membresias.membresias DROP COLUMN IF EXISTS vigencia_fin');
    }
};
