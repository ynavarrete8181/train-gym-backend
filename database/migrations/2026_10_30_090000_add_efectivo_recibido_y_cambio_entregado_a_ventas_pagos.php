<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('ventas.pagos', function (Blueprint $table): void {
            $table->decimal('efectivo_recibido', 12, 2)->nullable();
            $table->decimal('cambio_entregado', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('ventas.pagos', function (Blueprint $table): void {
            $table->dropColumn(['efectivo_recibido', 'cambio_entregado']);
        });
    }
};
