<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->asegurarEstadosReserva();

        $tablas = [
            ['gimnasio.membresias', 'MEMBRESIA'],
            ['ventas.ventas', 'VENTA'],
            ['ventas.pagos', 'PAGO'],
            ['gimnasio.reservas_dia', 'RESERVA'],
        ];

        foreach ($tablas as [$tabla, $entidad]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            if (! Schema::hasColumn($tabla, 'estado_id')) {
                Schema::table($tabla, function (Blueprint $table): void {
                    $table->unsignedBigInteger('estado_id')->nullable()->index();
                });
            }

            DB::statement("UPDATE {$tabla} t
                SET estado_id = e.id
                FROM configuracion.estados_catalogo e
                WHERE e.entidad = ?
                  AND UPPER(e.valor_interno) = UPPER(t.estado)
                  AND (t.estado_id IS NULL OR t.estado_id <> e.id)", [$entidad]);

            $constraint = str_replace(['.', '-'], '_', $tabla) . '_estado_id_fk';
            $existeConstraint = DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conname = ? LIMIT 1",
                [$constraint]
            );

            if (! $existeConstraint) {
                DB::statement("ALTER TABLE {$tabla}
                    ADD CONSTRAINT {$constraint}
                    FOREIGN KEY (estado_id)
                    REFERENCES configuracion.estados_catalogo(id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT");
            }
        }
    }

    public function down(): void
    {
        foreach (['gimnasio.membresias', 'ventas.ventas', 'ventas.pagos', 'gimnasio.reservas_dia'] as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'estado_id')) {
                continue;
            }

            $constraint = str_replace(['.', '-'], '_', $tabla) . '_estado_id_fk';
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$constraint}");
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('estado_id');
            });
        }
    }

    private function asegurarEstadosReserva(): void
    {
        DB::table('configuracion.estados_catalogo')
            ->where('entidad', 'RESERVA')
            ->whereIn('valor_interno', ['PENDIENTE', 'CONFIRMADA'])
            ->update(['activo' => false, 'updated_at' => now()]);

        $estados = [
            ['RES_RESERVADA', 'RESERVADA', 'Reservada', 'Reserva registrada y vigente.', 'info', 1, true, false],
            ['RES_ASISTIO', 'ASISTIO', 'Asistió', 'El cliente asistió a la reserva.', 'success', 2, false, true],
            ['RES_CANCELADA', 'CANCELADA', 'Cancelada', 'Reserva cancelada.', 'error', 3, false, true],
            ['RES_NO_ASISTIO', 'NO_ASISTIO', 'No asistió', 'El cliente no asistió a la reserva.', 'warning', 4, false, true],
        ];

        foreach ($estados as [$codigo, $valor, $nombre, $descripcion, $color, $orden, $inicial, $final]) {
            DB::table('configuracion.estados_catalogo')->updateOrInsert(
                ['entidad' => 'RESERVA', 'valor_interno' => $valor],
                [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'color' => $color,
                    'orden' => $orden,
                    'activo' => true,
                    'es_inicial' => $inicial,
                    'es_final' => $final,
                    'protegido_sistema' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
