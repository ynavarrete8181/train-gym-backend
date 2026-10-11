<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notificaciones.plantillas')
            ->whereIn('tipo', ['ACCESO', 'RESTABLECIMIENTO'])
            ->update([
                'cuerpo_html' => DB::raw("REPLACE(cuerpo_html, 'font-size:20px;letter-spacing:2px', 'font-size:14px;letter-spacing:.5px')"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('notificaciones.plantillas')
            ->whereIn('tipo', ['ACCESO', 'RESTABLECIMIENTO'])
            ->update([
                'cuerpo_html' => DB::raw("REPLACE(cuerpo_html, 'font-size:14px;letter-spacing:.5px', 'font-size:20px;letter-spacing:2px')"),
                'updated_at' => now(),
            ]);
    }
};
