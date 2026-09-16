<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('configuracion.estados_catalogo', 'valor_interno')) {
            Schema::table('configuracion.estados_catalogo', function (Blueprint $table): void {
                $table->string('valor_interno', 80)->nullable()->after('entidad');
                $table->unique(['entidad', 'valor_interno']);
            });
        }

        $valores = [
            'MEM_PENDIENTE_PAGO' => 'PENDIENTE_PAGO',
            'MEM_ACTIVA' => 'ACTIVA',
            'MEM_CONGELADA' => 'CONGELADA',
            'MEM_VENCIDA' => 'VENCIDA',
            'MEM_CANCELADA' => 'CANCELADA',
            'VEN_PENDIENTE' => 'PENDIENTE',
            'VEN_PARCIAL' => 'PARCIAL',
            'VEN_PAGADA' => 'PAGADA',
            'VEN_ANULADA' => 'ANULADA',
            'PAG_CONFIRMADO' => 'CONFIRMADO',
            'PAG_ANULADO' => 'ANULADO',
            'RES_PENDIENTE' => 'PENDIENTE',
            'RES_CONFIRMADA' => 'CONFIRMADA',
            'RES_CANCELADA' => 'CANCELADA',
        ];

        foreach ($valores as $codigo => $valor) {
            DB::table('configuracion.estados_catalogo')->where('codigo', $codigo)->update([
                'valor_interno' => $valor,
                'updated_at' => now(),
            ]);
        }

        DB::statement('ALTER TABLE configuracion.estados_catalogo ALTER COLUMN valor_interno SET NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('configuracion.estados_catalogo', 'valor_interno')) {
            Schema::table('configuracion.estados_catalogo', function (Blueprint $table): void {
                $table->dropUnique(['entidad', 'valor_interno']);
                $table->dropColumn('valor_interno');
            });
        }
    }
};
