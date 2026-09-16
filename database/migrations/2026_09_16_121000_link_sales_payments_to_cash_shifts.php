<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ventas.ventas', 'turno_caja_id')) {
            Schema::table('ventas.ventas', function (Blueprint $table): void {
                $table->unsignedBigInteger('turno_caja_id')->nullable()->after('caja_id')->index();
            });
            DB::statement('ALTER TABLE ventas.ventas ADD CONSTRAINT ventas_turno_caja_fk FOREIGN KEY (turno_caja_id) REFERENCES ventas.turnos_caja(id)');
        }

        if (! Schema::hasColumn('ventas.pagos', 'turno_caja_id')) {
            Schema::table('ventas.pagos', function (Blueprint $table): void {
                $table->unsignedBigInteger('turno_caja_id')->nullable()->after('caja_id')->index();
            });
            DB::statement('ALTER TABLE ventas.pagos ADD CONSTRAINT pagos_turno_caja_fk FOREIGN KEY (turno_caja_id) REFERENCES ventas.turnos_caja(id)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ventas.pagos', 'turno_caja_id')) {
            DB::statement('ALTER TABLE ventas.pagos DROP CONSTRAINT IF EXISTS pagos_turno_caja_fk');
            Schema::table('ventas.pagos', fn (Blueprint $table) => $table->dropColumn('turno_caja_id'));
        }
        if (Schema::hasColumn('ventas.ventas', 'turno_caja_id')) {
            DB::statement('ALTER TABLE ventas.ventas DROP CONSTRAINT IF EXISTS ventas_turno_caja_fk');
            Schema::table('ventas.ventas', fn (Blueprint $table) => $table->dropColumn('turno_caja_id'));
        }
    }
};
