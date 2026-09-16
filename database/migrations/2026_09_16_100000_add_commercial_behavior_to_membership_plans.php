<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('gimnasio.planes', function (Blueprint $table): void {
            $table->string('tipo_producto', 30)->default('MEMBRESIA');
            $table->string('tipo_cobro', 20)->default('PAGO_UNICO');
            $table->boolean('generar_venta')->default(true);
            $table->boolean('requiere_pago')->default(true);
            $table->boolean('renovable')->default(true);
        });

        DB::table('gimnasio.planes')
            ->whereIn('nombre', ['Deportivo', 'Fortalecimiento / Rehabilitación', 'Funcional', 'Híbrido', 'Musculación'])
            ->update([
                'tipo_producto' => 'MEMBRESIA',
                'tipo_cobro' => 'RECURRENTE',
                'generar_venta' => true,
                'requiere_pago' => true,
                'renovable' => true,
                'updated_at' => now(),
            ]);

        DB::table('gimnasio.planes')
            ->whereIn('nombre', ['Pase Diario', 'Pase Diario Familia'])
            ->update([
                'tipo_producto' => 'PASE_DIARIO',
                'tipo_cobro' => 'PAGO_UNICO',
                'generar_venta' => true,
                'requiere_pago' => true,
                'renovable' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('gimnasio.planes', function (Blueprint $table): void {
            $table->dropColumn(['tipo_producto', 'tipo_cobro', 'generar_venta', 'requiere_pago', 'renovable']);
        });
    }
};
