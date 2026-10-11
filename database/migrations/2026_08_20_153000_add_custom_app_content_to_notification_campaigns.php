<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones.campanias', function (Blueprint $table): void {
            $table->string('titulo_app', 255)->nullable();
            $table->text('descripcion_app')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones.campanias', function (Blueprint $table): void {
            $table->dropColumn(['titulo_app', 'descripcion_app']);
        });
    }
};
