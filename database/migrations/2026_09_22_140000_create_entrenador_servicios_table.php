<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gimnasio.entrenador_servicios')) {
            Schema::create('gimnasio.entrenador_servicios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entrenador_id')->constrained('gimnasio.entrenadores')->cascadeOnDelete();
                $table->foreignId('servicio_id')->constrained('gimnasio.servicios')->cascadeOnDelete();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['entrenador_id', 'servicio_id']);
                $table->index(['servicio_id', 'activo']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gimnasio.entrenador_servicios');
    }
};
