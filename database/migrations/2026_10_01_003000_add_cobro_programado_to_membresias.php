<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membresias.membresias', function (Blueprint $table): void {
            if (! Schema::hasColumn('membresias.membresias', 'dia_pago')) {
                $table->unsignedTinyInteger('dia_pago')->nullable()->after('dias_gracia');
            }
            if (! Schema::hasColumn('membresias.membresias', 'generar_venta_automatica')) {
                $table->boolean('generar_venta_automatica')->default(false)->after('dia_pago');
            }
            if (! Schema::hasColumn('membresias.membresias', 'proxima_fecha_cobro')) {
                $table->date('proxima_fecha_cobro')->nullable()->after('generar_venta_automatica');
            }
        });
    }

    public function down(): void
    {
        Schema::table('membresias.membresias', function (Blueprint $table): void {
            foreach (['proxima_fecha_cobro', 'generar_venta_automatica', 'dia_pago'] as $columna) {
                if (Schema::hasColumn('membresias.membresias', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
