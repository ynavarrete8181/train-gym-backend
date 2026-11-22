<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Orden del submenú Reportes: de lo general (resumen) al detalle de cobros, deudas, ventas, membresías y caja. */
return new class extends Migration
{
    private const ORDEN = [
        'REPORTES-COMERCIAL-RESUMEN' => 1,
        'REPORTES-COBROS-PERIODO' => 2,
        'REPORTES-CARTERA-VENCIDA' => 3,
        'REPORTES-COBROS-METODOS' => 4,
        'REPORTES-VENTAS-PERIODO' => 5,
        'REPORTES-VENTAS-RESPONSABLE' => 6,
        'REPORTES-MEMBRESIAS-NUEVAS-RENOVACIONES' => 7,
        'REPORTES-MEMBRESIAS-POR-VENCER' => 8,
        'REPORTES-CONCILIACION-CAJA' => 9,
        'REPORTES-PRODUCTOS-SERVICIOS' => 10,
    ];

    public function up(): void
    {
        foreach (self::ORDEN as $menu => $orden) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)->where('id_menu', $menu)->update(['orden' => $orden, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Solo cambia el orden de presentación; no hace falta revertirlo.
    }
};
