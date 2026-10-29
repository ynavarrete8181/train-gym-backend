<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas.persona_ubicaciones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('persona_id')->unique();
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->string('referencia', 240)->nullable();
            $table->boolean('ubicacion_voluntaria')->default(true);
            $table->timestamps();
            $table->foreign('persona_id', 'ubicaciones_persona_fk')
                ->references('id')->on('personas.personas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas.persona_ubicaciones');
    }
};
