<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas.turnos_caja', function (Blueprint $table): void {
            $table->decimal('efectivo_cobrado', 12, 2)->default(0);
            $table->decimal('transferencia_cobrada', 12, 2)->default(0);
            $table->decimal('tarjeta_cobrada', 12, 2)->default(0);
            $table->decimal('deposito_cobrado', 12, 2)->default(0);
            $table->decimal('otros_cobrado', 12, 2)->default(0);
            $table->decimal('total_cobrado', 12, 2)->default(0);
            $table->unsignedInteger('cantidad_cobros')->default(0);
        });

        DB::statement("
            UPDATE ventas.turnos_caja t
            SET
                efectivo_cobrado = COALESCE(x.efectivo, 0),
                transferencia_cobrada = COALESCE(x.transferencia, 0),
                tarjeta_cobrada = COALESCE(x.tarjeta, 0),
                deposito_cobrado = COALESCE(x.deposito, 0),
                otros_cobrado = COALESCE(x.otros, 0),
                total_cobrado = COALESCE(x.total, 0),
                cantidad_cobros = COALESCE(x.cantidad, 0)
            FROM (
                SELECT
                    turno_caja_id,
                    SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END) AS efectivo,
                    SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) AS transferencia,
                    SUM(CASE WHEN metodo_pago = 'TARJETA' THEN monto ELSE 0 END) AS tarjeta,
                    SUM(CASE WHEN metodo_pago = 'DEPOSITO' THEN monto ELSE 0 END) AS deposito,
                    SUM(CASE WHEN metodo_pago NOT IN ('EFECTIVO','TRANSFERENCIA','TARJETA','DEPOSITO') THEN monto ELSE 0 END) AS otros,
                    SUM(monto) AS total,
                    COUNT(DISTINCT COALESCE(operacion_cobro_id, 'PAGO-' || id::text)) AS cantidad
                FROM ventas.pagos
                WHERE estado = 'CONFIRMADO' AND turno_caja_id IS NOT NULL
                GROUP BY turno_caja_id
            ) x
            WHERE x.turno_caja_id = t.id
        ");
    }

    public function down(): void
    {
        Schema::table('ventas.turnos_caja', function (Blueprint $table): void {
            $table->dropColumn([
                'efectivo_cobrado',
                'transferencia_cobrada',
                'tarjeta_cobrada',
                'deposito_cobrado',
                'otros_cobrado',
                'total_cobrado',
                'cantidad_cobros',
            ]);
        });
    }
};
