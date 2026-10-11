<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('ventas.pagos', function (Blueprint $table): void {
            if (! Schema::connection('pgsql')->hasColumn('ventas.pagos', 'operacion_cobro_id')) {
                $table->string('operacion_cobro_id', 80)->nullable()->after('turno_caja_id');
                $table->index('operacion_cobro_id');
            }
        });

        $pagos = DB::table('ventas.pagos')
            ->whereNull('operacion_cobro_id')
            ->orderBy('id')
            ->get(['id', 'venta_id', 'turno_caja_id', 'usuario_id', 'fecha_pago']);

        $grupos = $pagos->groupBy(function ($pago): string {
            $fecha = $pago->fecha_pago ? substr((string) $pago->fecha_pago, 0, 19) : 'SIN-FECHA';

            return implode('|', [
                $pago->venta_id ?? 'SIN-VENTA',
                $pago->turno_caja_id ?? 'SIN-TURNO',
                $pago->usuario_id ?? 'SIN-USUARIO',
                $fecha,
            ]);
        });

        foreach ($grupos as $grupo) {
            $operacion = 'COBRO-LEGACY-' . Str::upper(Str::ulid()->toBase32());

            DB::table('ventas.pagos')
                ->whereIn('id', $grupo->pluck('id')->all())
                ->update([
                    'operacion_cobro_id' => $operacion,
                    'updated_at' => now(),
                ]);
        }

        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'VENTAS-PAGOS')
            ->update([
                'nombre' => 'Cobros',
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'VENTAS-PAGOS')
            ->update([
                'nombre' => 'Cobros',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('seguridad.cpu_userrolefunction')
            ->where('id_menu', 'VENTAS-PAGOS')
            ->update([
                'nombre' => 'Pagos',
                'updated_at' => now(),
            ]);

        DB::table('seguridad.cpu_userfunction')
            ->where('id_menu', 'VENTAS-PAGOS')
            ->update([
                'nombre' => 'Pagos',
                'updated_at' => now(),
            ]);

        Schema::connection('pgsql')->table('ventas.pagos', function (Blueprint $table): void {
            if (Schema::connection('pgsql')->hasColumn('ventas.pagos', 'operacion_cobro_id')) {
                $table->dropIndex(['operacion_cobro_id']);
                $table->dropColumn('operacion_cobro_id');
            }
        });
    }
};
