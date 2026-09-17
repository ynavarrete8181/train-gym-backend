<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gimnasio.servicio_precios_sede')) {
            Schema::create('gimnasio.servicio_precios_sede', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('servicio_id')->constrained('gimnasio.servicios')->cascadeOnDelete();
                $table->unsignedBigInteger('sede_id');
                $table->decimal('precio', 10, 2);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->cascadeOnDelete();
                $table->unique(['servicio_id', 'sede_id'], 'uq_servicio_precio_sede');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gimnasio.servicio_precios_sede');
    }
};
