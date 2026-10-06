<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas.turnos_caja', function (Blueprint $table): void {
            $table->string('tipo_cierre', 20)->nullable()->after('estado');
            $table->boolean('requiere_arqueo')->default(false)->after('tipo_cierre');
            $table->timestamp('cierre_automatico_at')->nullable()->after('requiere_arqueo');
            $table->timestamp('conciliado_at')->nullable()->after('cierre_automatico_at');
            $table->unsignedBigInteger('conciliado_por')->nullable()->after('conciliado_at');
        });
    }

    public function down(): void
    {
        Schema::table('ventas.turnos_caja', function (Blueprint $table): void {
            $table->dropColumn([
                'tipo_cierre',
                'requiere_arqueo',
                'cierre_automatico_at',
                'conciliado_at',
                'conciliado_por',
            ]);
        });
    }
};
