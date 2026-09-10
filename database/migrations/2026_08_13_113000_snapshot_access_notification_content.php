<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notificaciones.notificaciones ADD COLUMN asunto_enviado VARCHAR(255) NULL');
        DB::statement('ALTER TABLE notificaciones.notificaciones ADD COLUMN cuerpo_html_enviado TEXT NULL');
        DB::statement('ALTER TABLE notificaciones.notificaciones ADD COLUMN cuerpo_texto_enviado TEXT NULL');
        DB::statement('ALTER TABLE notificaciones.notificaciones ADD COLUMN variables_enviadas JSONB NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notificaciones.notificaciones DROP COLUMN IF EXISTS variables_enviadas');
        DB::statement('ALTER TABLE notificaciones.notificaciones DROP COLUMN IF EXISTS cuerpo_texto_enviado');
        DB::statement('ALTER TABLE notificaciones.notificaciones DROP COLUMN IF EXISTS cuerpo_html_enviado');
        DB::statement('ALTER TABLE notificaciones.notificaciones DROP COLUMN IF EXISTS asunto_enviado');
    }
};
