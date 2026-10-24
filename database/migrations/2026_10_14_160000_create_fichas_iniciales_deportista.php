<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes.fichas_iniciales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('deportista_id')->unique();
            $table->unsignedBigInteger('registrado_por');
            $table->string('estado', 30)->default('BORRADOR');
            $table->jsonb('antecedentes')->default('{}');
            $table->jsonb('evaluacion')->default('{}');
            $table->jsonb('objetivos')->default('{}');
            $table->jsonb('valoracion')->default('{}');
            $table->timestamp('completado_at')->nullable();
            $table->timestamps();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
            $table->foreign('registrado_por')->references('id')->on('seguridad.users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes.fichas_iniciales');
    }
};
