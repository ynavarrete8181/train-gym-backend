<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 1: estructura contractual y calendario de cobros.
     *
     * No genera ventas, pagos ni cuotas para contratos existentes. Los cobros
     * y la aplicación de anticipos se implementan en servicios posteriores.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('membresias.membresias', 'cantidad_periodos_contratados')) {
            Schema::table('membresias.membresias', function (Blueprint $table): void {
                // NULL indica contrato anterior, cuya cantidad no se debe inferir.
                $table->unsignedInteger('cantidad_periodos_contratados')->nullable();
            });
        }

        if (! Schema::hasTable('membresias.membresia_cuotas')) {
            Schema::create('membresias.membresia_cuotas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('membresia_id')
                    ->constrained('membresias.membresias')
                    ->cascadeOnDelete();
                $table->unsignedInteger('numero_cuota');
                // Puede no existir correspondencia 1:1 entre cuota y periodo.
                $table->unsignedBigInteger('periodo_id')->nullable();
                $table->decimal('monto', 12, 2);
                // Fecha de creación comercial de la venta; distinta del vencimiento.
                $table->date('fecha_programada_emision');
                $table->date('fecha_vencimiento');
                $table->string('estado', 20)->default('PROGRAMADA');
                $table->unsignedBigInteger('venta_id')->nullable();
                $table->timestamp('emitida_at')->nullable();
                $table->timestamp('anulada_at')->nullable();
                $table->timestamps();

                $table->foreign('periodo_id')
                    ->references('id')->on('membresias.membresia_periodos')
                    ->nullOnDelete();
                $table->foreign('venta_id')
                    ->references('id')->on('ventas.ventas')
                    ->nullOnDelete();

                $table->unique(['membresia_id', 'numero_cuota'], 'membresia_cuotas_contrato_numero_unique');
                $table->index(['estado', 'fecha_programada_emision'], 'membresia_cuotas_emision_idx');
                $table->index(['fecha_vencimiento', 'estado'], 'membresia_cuotas_vencimiento_idx');
                $table->index('periodo_id', 'membresia_cuotas_periodo_idx');
                $table->index('venta_id', 'membresia_cuotas_venta_idx');
            });
        }

        // Restricciones en PostgreSQL: las cuotas nunca tienen importe negativo
        // ni numeración cero. El estado financiero PAGADA se deriva de pagos
        // confirmados y no se almacena aquí.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE membresias.membresia_cuotas ADD CONSTRAINT membresia_cuotas_numero_positivo CHECK (numero_cuota > 0)');
            DB::statement('ALTER TABLE membresias.membresia_cuotas ADD CONSTRAINT membresia_cuotas_monto_no_negativo CHECK (monto >= 0)');
            DB::statement("ALTER TABLE membresias.membresia_cuotas ADD CONSTRAINT membresia_cuotas_estado_valido CHECK (estado IN ('PROGRAMADA', 'EMITIDA', 'ANULADA'))");
            DB::statement('ALTER TABLE membresias.membresias ADD CONSTRAINT membresias_cantidad_periodos_positiva CHECK (cantidad_periodos_contratados IS NULL OR cantidad_periodos_contratados > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membresias.membresia_cuotas');

        if (Schema::hasColumn('membresias.membresias', 'cantidad_periodos_contratados')) {
            Schema::table('membresias.membresias', function (Blueprint $table): void {
                $table->dropColumn('cantidad_periodos_contratados');
            });
        }
    }
};
