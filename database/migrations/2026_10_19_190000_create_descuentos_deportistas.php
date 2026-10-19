<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('membresias.descuentos_deportistas', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('deportista_id')->constrained('clientes.deportistas')->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('membresias.planes')->restrictOnDelete();
            $table->foreignId('modalidad_id')->nullable()->constrained('membresias.plan_modalidades')->restrictOnDelete();
            $table->string('tipo', 12);
            $table->decimal('valor', 12, 2);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('motivo', 250);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('autorizado_por');
            $table->timestamps();
            $table->index(['deportista_id', 'activo', 'fecha_inicio', 'fecha_fin'], 'idx_descuentos_deportista_vigencia');
        });
    }
    public function down(): void {
        Schema::dropIfExists('membresias.descuentos_deportistas');
    }
};
