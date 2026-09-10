<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones.campanias', function (Blueprint $table): void {
            $table->boolean('publicar_inicio_app')->default(false);
            $table->string('imagen_inicio_app', 1000)->nullable();
            $table->string('accion_url_app', 1000)->nullable();
            $table->string('accion_etiqueta_app', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones.campanias', function (Blueprint $table): void {
            $table->dropColumn([
                'publicar_inicio_app',
                'imagen_inicio_app',
                'accion_url_app',
                'accion_etiqueta_app',
            ]);
        });
    }
};
