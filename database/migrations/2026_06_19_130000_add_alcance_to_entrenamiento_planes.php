<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE entrenamiento.planes ADD COLUMN IF NOT EXISTS alcance VARCHAR(20) DEFAULT 'GRUPAL'");
        DB::statement("UPDATE entrenamiento.planes SET alcance = 'GRUPAL' WHERE alcance IS NULL OR TRIM(alcance) = ''");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entrenamiento.planes DROP COLUMN IF EXISTS alcance');
    }
};
