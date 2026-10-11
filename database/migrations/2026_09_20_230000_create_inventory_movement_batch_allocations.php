<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventario.movimiento_lotes')) {
            Schema::create('inventario.movimiento_lotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('movimiento_id')->constrained('inventario.movimientos')->cascadeOnDelete();
                $table->foreignId('lote_id')->constrained('inventario.lotes_producto')->restrictOnDelete();
                $table->decimal('cantidad', 12, 2);
                $table->timestamps();

                $table->unique(['movimiento_id', 'lote_id']);
                $table->index('lote_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.movimiento_lotes');
    }
};
