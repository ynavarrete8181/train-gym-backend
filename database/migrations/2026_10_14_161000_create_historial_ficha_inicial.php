<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes.fichas_iniciales_historial', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ficha_id');
            $table->unsignedBigInteger('modificado_por');
            $table->string('seccion', 30);
            $table->jsonb('datos_anteriores')->nullable();
            $table->jsonb('datos_nuevos');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('ficha_id')->references('id')->on('clientes.fichas_iniciales')->restrictOnDelete();
            $table->foreign('modificado_por')->references('id')->on('seguridad.users')->restrictOnDelete();
            $table->index(['ficha_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes.fichas_iniciales_historial');
    }
};
