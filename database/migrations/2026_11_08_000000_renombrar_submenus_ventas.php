<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menú Ventas: "Turnos de caja" pasa a "Apertura y cierre de caja" y se separa
 * el orden de Cartera y Comprobantes. Aplica a los submenús de usuarios y de roles.
 */
return new class extends Migration
{
    private const ORDEN = [
        'VENTAS-CAJAS' => 1,
        'VENTAS-TURNOS-CAJA' => 2,
        'VENTAS-VENTAS' => 3,
        'VENTAS-PAGOS' => 4,
        'VENTAS-CARTERA' => 5,
        'VENTAS-COMPROBANTES' => 6,
    ];

    public function up(): void
    {
        $this->aplicar('Apertura y cierre de caja', self::ORDEN);
    }

    public function down(): void
    {
        $this->aplicar('Turnos de caja', array_merge(self::ORDEN, ['VENTAS-COMPROBANTES' => 5]));
    }

    private function aplicar(string $nombreTurnos, array $orden): void
    {
        foreach (['seguridad.cpu_userfunction', 'seguridad.cpu_userrolefunction'] as $tabla) {
            DB::table($tabla)->where('id_menu', 'VENTAS-TURNOS-CAJA')->update(['nombre' => $nombreTurnos, 'updated_at' => now()]);
            foreach ($orden as $idMenu => $posicion) {
                DB::table($tabla)->where('id_menu', $idMenu)->update(['orden' => $posicion, 'updated_at' => now()]);
            }
        }
    }
};
