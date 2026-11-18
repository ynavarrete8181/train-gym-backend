<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Banco de una transferencia o depósito, para que Cobros muestre el número y el banco
 * de cada pago. La transferencia 77961867 de la familia Montesdeoca fue de Banco Pichincha.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ventas.pagos ADD COLUMN IF NOT EXISTS banco varchar(80) NULL');
        DB::table('ventas.pagos')->where('referencia', '77961867')->where('metodo_pago', 'TRANSFERENCIA')->whereNull('banco')
            ->update(['banco' => 'Banco Pichincha', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ventas.pagos DROP COLUMN IF EXISTS banco');
    }
};
