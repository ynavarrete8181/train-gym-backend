<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE notificaciones.campanias ADD COLUMN correos_cc JSONB NOT NULL DEFAULT '[]', ADD COLUMN correos_cco JSONB NOT NULL DEFAULT '[]'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notificaciones.campanias DROP COLUMN IF EXISTS correos_cc, DROP COLUMN IF EXISTS correos_cco');
    }
};
