<?php

use App\Services\Gimnasio\ContratoMembresiaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MEMB-000018: el descuento de $20 (Home, 18/11–17/12) se registró tres minutos después de
 * generar la venta del período 1, que quedó en $80 sin descuento. Se recalcula con la regla
 * nueva (solo ventas pendientes sin cobros) y queda en el historial del contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('membresias.membresias')->where('codigo_contrato', 'MEMB-000018')->value('id');
        if (! $id) {
            return;
        }
        DB::transaction(fn () => app(ContratoMembresiaServicio::class)->recalcularVentasPendientes((int) $id, null,
            'Corrección: el descuento se registró después de generar la venta del período; se aplica a la venta pendiente.'));
    }

    public function down(): void
    {
        // Sin reversión automática: el ajuste queda en el historial del contrato.
    }
};
