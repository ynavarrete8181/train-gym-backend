<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 1: solo esquema. No cambia contratos, ventas, cuotas,
     * estados ni reservas existentes.
     */
    public function up(): void
    {
        if (! Schema::hasTable('membresias.membresia_movimientos')) {
            Schema::create('membresias.membresia_movimientos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('membresia_id')->constrained('membresias.membresias')->restrictOnDelete();
                $table->string('tipo', 32);
                $table->string('estado', 20)->default('CONFIRMADO');
                $table->date('fecha_efectiva');
                $table->text('motivo');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->unsignedBigInteger('autorizado_por')->nullable();
                $table->unsignedBigInteger('movimiento_origen_id')->nullable();
                $table->jsonb('detalle')->nullable();
                $table->timestamps();

                $table->foreign('usuario_id')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->foreign('autorizado_por')->references('id')->on('seguridad.users')->nullOnDelete();
                $table->foreign('movimiento_origen_id')->references('id')->on('membresias.membresia_movimientos')->restrictOnDelete();
                $table->index(['membresia_id', 'fecha_efectiva'], 'membresia_movimientos_contrato_fecha_idx');
                $table->index(['tipo', 'estado', 'fecha_efectiva'], 'membresia_movimientos_tipo_estado_idx');
            });
        }

        if (! Schema::hasTable('membresias.membresia_suspensiones')) {
            Schema::create('membresias.membresia_suspensiones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('membresia_id')->constrained('membresias.membresias')->restrictOnDelete();
                $table->foreignId('movimiento_inicio_id')->constrained('membresias.membresia_movimientos')->restrictOnDelete();
                $table->unsignedBigInteger('movimiento_fin_id')->nullable();
                $table->string('tipo', 24); // TIEMPO | FACTURACION
                $table->string('estado', 20)->default('PROGRAMADA');
                $table->date('fecha_inicio');
                $table->date('fecha_fin_prevista'); // Inclusiva
                $table->date('fecha_fin_real')->nullable(); // Inclusiva, al reactivar
                $table->unsignedInteger('dias_reconocidos')->default(0);
                $table->date('fecha_fin_original')->nullable();
                $table->date('fecha_fin_ajustada')->nullable();
                $table->timestamps();

                $table->foreign('movimiento_fin_id')->references('id')->on('membresias.membresia_movimientos')->restrictOnDelete();
                $table->unique('movimiento_inicio_id', 'membresia_suspensiones_inicio_unico');
                $table->unique('movimiento_fin_id', 'membresia_suspensiones_fin_unico');
                $table->index(['membresia_id', 'estado', 'fecha_inicio'], 'membresia_suspensiones_contrato_estado_idx');
                $table->index(['estado', 'fecha_inicio', 'fecha_fin_prevista'], 'membresia_suspensiones_calendario_idx');
            });
        }

        if (! Schema::hasTable('membresias.membresia_reprogramaciones')) {
            Schema::create('membresias.membresia_reprogramaciones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('membresia_id')->constrained('membresias.membresias')->restrictOnDelete();
                $table->foreignId('movimiento_id')->constrained('membresias.membresia_movimientos')->restrictOnDelete();
                $table->unsignedBigInteger('cuota_id')->nullable();
                $table->unsignedBigInteger('periodo_id')->nullable();
                $table->date('emision_anterior')->nullable();
                $table->date('emision_nueva')->nullable();
                $table->date('vencimiento_anterior')->nullable();
                $table->date('vencimiento_nuevo')->nullable();
                $table->date('vigencia_fin_anterior')->nullable();
                $table->date('vigencia_fin_nueva')->nullable();
                $table->string('estado', 20)->default('PROPUESTA');
                $table->text('motivo');
                $table->jsonb('impacto_financiero')->nullable();
                $table->timestamps();

                $table->foreign('cuota_id')->references('id')->on('membresias.membresia_cuotas')->restrictOnDelete();
                $table->foreign('periodo_id')->references('id')->on('membresias.membresia_periodos')->restrictOnDelete();
                $table->index(['membresia_id', 'movimiento_id'], 'membresia_reprogramaciones_contrato_mov_idx');
                $table->index(['cuota_id', 'estado'], 'membresia_reprogramaciones_cuota_estado_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membresias.membresia_reprogramaciones');
        Schema::dropIfExists('membresias.membresia_suspensiones');
        Schema::dropIfExists('membresias.membresia_movimientos');
    }
};
