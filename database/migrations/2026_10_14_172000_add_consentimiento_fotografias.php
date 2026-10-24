<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clientes.valoracion_fotografias', function (Blueprint $table): void {
            $table->boolean('consentimiento_confirmado')->default(false);
            $table->timestamp('consentimiento_confirmado_at')->nullable();
            $table->unsignedBigInteger('consentimiento_registrado_por')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clientes.valoracion_fotografias', function (Blueprint $table): void {
            $table->dropColumn(['consentimiento_confirmado', 'consentimiento_confirmado_at', 'consentimiento_registrado_por']);
        });
    }
};
