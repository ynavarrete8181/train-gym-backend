<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un descuento puede valer en todas las sedes autorizadas (sede_id nulo, como hasta ahora)
 * o solo en una sede: al cobrar se usa el de la sede de la caja y, si no hay, el general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membresias.descuentos_deportistas', function (Blueprint $table): void {
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('membresias.descuentos_deportistas', function (Blueprint $table): void {
            $table->dropForeign(['sede_id']);
            $table->dropColumn('sede_id');
        });
    }
};
