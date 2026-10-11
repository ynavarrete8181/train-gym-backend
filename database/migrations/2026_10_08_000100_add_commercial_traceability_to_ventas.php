<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas.ventas', function (Blueprint $table): void {
            $table->string('origen_tipo', 40)->nullable()->after('tipo_venta');
            $table->unsignedBigInteger('origen_id')->nullable()->after('origen_tipo');
            $table->string('generado_por_tipo', 20)->nullable()->after('origen_id');
            $table->unsignedBigInteger('responsable_comercial_id')->nullable()->after('usuario_id');

            $table->index(['origen_tipo', 'origen_id'], 'ventas_origen_idx');
            $table->index('responsable_comercial_id', 'ventas_responsable_comercial_idx');
        });

        DB::statement("
            UPDATE ventas.ventas
               SET origen_tipo = CASE
                    WHEN membresia_id IS NOT NULL THEN 'MEMBRESIA'
                    WHEN UPPER(COALESCE(tipo_venta, '')) = 'PRODUCTO' THEN 'POS'
                    WHEN UPPER(COALESCE(tipo_venta, '')) = 'SERVICIO' THEN 'SERVICIO'
                    ELSE 'POS'
               END,
                   origen_id = CASE WHEN membresia_id IS NOT NULL THEN membresia_id ELSE NULL END,
                   generado_por_tipo = CASE WHEN usuario_id IS NULL THEN 'SISTEMA' ELSE 'USUARIO' END
             WHERE origen_tipo IS NULL
                OR generado_por_tipo IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('ventas.ventas', function (Blueprint $table): void {
            $table->dropIndex('ventas_origen_idx');
            $table->dropIndex('ventas_responsable_comercial_idx');
            $table->dropColumn([
                'origen_tipo',
                'origen_id',
                'generado_por_tipo',
                'responsable_comercial_id',
            ]);
        });
    }
};
