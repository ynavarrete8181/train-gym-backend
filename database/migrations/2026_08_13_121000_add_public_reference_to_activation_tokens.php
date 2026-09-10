<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notificaciones.tokens_activacion ADD COLUMN referencia_publica UUID NULL');
        DB::statement('CREATE UNIQUE INDEX tokens_activacion_referencia_publica_idx ON notificaciones.tokens_activacion(referencia_publica) WHERE referencia_publica IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notificaciones.tokens_activacion_referencia_publica_idx');
        DB::statement('ALTER TABLE notificaciones.tokens_activacion DROP COLUMN IF EXISTS referencia_publica');
    }
};
