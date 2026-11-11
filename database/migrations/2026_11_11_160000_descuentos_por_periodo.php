<?php

use App\Services\Gimnasio\DescuentoDeportistaServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada renovación continúa el descuento vigente como un registro propio del
 * período (mismas fechas), enlazado al descuento del que proviene.
 * Además separa por período el descuento de MEMB-000002 (75 % desde 01/10 "sin fin").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membresias.descuentos_deportistas', function (Blueprint $table): void {
            $table->foreignId('descuento_origen_id')->nullable()
                ->constrained('membresias.descuentos_deportistas')->nullOnDelete();
            $table->foreignId('membresia_periodo_id')->nullable()
                ->constrained('membresias.membresia_periodos')->nullOnDelete();
        });

        $this->separarDescuentoMemb000002();
    }

    private function separarDescuentoMemb000002(): void
    {
        $m = DB::table('membresias.membresias as m')
            ->join('clientes.deportistas as d', 'd.id', '=', 'm.deportista_id')
            ->where('m.codigo_contrato', 'MEMB-000002')
            ->where('d.codigo_deportista', 'VBVJH')
            ->first(['m.id']);
        if (! $m) return;

        $periodos = DB::table('membresias.membresia_periodos')
            ->where('membresia_id', $m->id)->where('numero_periodo', '>', 1)
            ->orderBy('numero_periodo')->get();
        $servicio = app(DescuentoDeportistaServicio::class);
        foreach ($periodos as $periodo) {
            $nuevo = $servicio->continuarEnPeriodo((int) $periodo->id, null);
            if ($nuevo && (int) ($nuevo->membresia_periodo_id ?? 0) === (int) $periodo->id) {
                DB::table('membresias.membresia_periodos')->where('id', $periodo->id)
                    ->whereNotNull('descuento_id')
                    ->update(['descuento_id' => $nuevo->id, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('membresias.descuentos_deportistas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('descuento_origen_id');
            $table->dropConstrainedForeignId('membresia_periodo_id');
        });
    }
};
