<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'INSTITUCIONAL-CAMPOS-ESPECIFICOS')
            ->delete();

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'INSTITUCIONAL-CAMPOS-ESPECIFICOS')
            ->delete();

        if (Schema::hasColumn('institucional.carreras_areas', 'id_campo_especifico')) {
            Schema::table('institucional.carreras_areas', function (Blueprint $table): void {
                $table->dropForeign(['id_campo_especifico']);
                $table->dropColumn('id_campo_especifico');
            });
        }

        if (Schema::hasColumn('institucional.carreras_areas', 'sede_senescyt')) {
            Schema::table('institucional.carreras_areas', function (Blueprint $table): void {
                $table->dropColumn('sede_senescyt');
            });
        }

        Schema::dropIfExists('institucional.campos_especificos');
    }

    public function down(): void
    {
        // Limpieza deliberada de conceptos propios del dominio de Admisión y Nivelación.
        // Revive no debe recrearlos durante un rollback.
    }
};
