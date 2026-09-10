<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notificaciones.plantillas ADD COLUMN cuerpo_texto TEXT NULL');
        DB::statement('ALTER TABLE notificaciones.campanias ADD COLUMN cuerpo_texto TEXT NULL');
        foreach (DB::table('notificaciones.plantillas')->get(['id', 'cuerpo_html']) as $plantilla) {
            DB::table('notificaciones.plantillas')->where('id', $plantilla->id)->update([
                'cuerpo_texto' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $plantilla->cuerpo_html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notificaciones.plantillas DROP COLUMN IF EXISTS cuerpo_texto');
        DB::statement('ALTER TABLE notificaciones.campanias DROP COLUMN IF EXISTS cuerpo_texto');
    }
};
