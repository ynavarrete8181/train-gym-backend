<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes.valoracion_fotografias', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('valoracion_registro_id');
            $table->unsignedBigInteger('deportista_id');
            $table->unsignedBigInteger('subido_por');
            $table->string('ubicacion_privada', 500);
            $table->string('mime_type', 40);
            $table->string('vista', 25);
            $table->date('fecha_fotografia');
            $table->timestamps();
            $table->foreign('valoracion_registro_id')->references('id')->on('clientes.valoracion_registros')->restrictOnDelete();
            $table->foreign('deportista_id')->references('id')->on('clientes.deportistas')->restrictOnDelete();
            $table->foreign('subido_por')->references('id')->on('seguridad.users');
            $table->index(['deportista_id','fecha_fotografia'], 'valoracion_fotos_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes.valoracion_fotografias');
    }
};
