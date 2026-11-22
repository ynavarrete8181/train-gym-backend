<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "Ventas por responsable" pasa a "Ventas y cobros por usuario": se agrupa por quien registró y quien cobró. */
return new class extends Migration
{
    public function up(): void
    {
        $this->renombrar('Ventas y cobros por usuario');
    }

    public function down(): void
    {
        $this->renombrar('Ventas por responsable');
    }

    private function renombrar(string $nombre): void
    {
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)->where('id_menu', 'REPORTES-VENTAS-RESPONSABLE')->update(['nombre' => $nombre, 'updated_at' => now()]);
        }
    }
};
