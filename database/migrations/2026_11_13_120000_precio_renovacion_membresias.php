<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo toma el precio el próximo período al renovar: CONTRATO (mantiene el pactado),
 * TARIFA (tarifa vigente de la sede principal) o FIJO (precio_renovacion). Nulo = comportamiento anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membresias.membresias', function (Blueprint $table): void {
            $table->string('precio_renovacion_modo', 12)->nullable();
            $table->decimal('precio_renovacion', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('membresias.membresias', function (Blueprint $table): void {
            $table->dropColumn(['precio_renovacion_modo', 'precio_renovacion']);
        });
    }
};
