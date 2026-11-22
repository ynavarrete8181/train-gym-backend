<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reportes disponibles" e "Historial de reportes" salen del submenú Reportes porque por
 * ahora no se usan. Se inactivan (no se borran): para volver a mostrarlos basta revertir.
 */
return new class extends Migration
{
    private const MENUS = ['REPORTES-DISPONIBLES', 'REPORTES-HISTORIAL'];

    public function up(): void
    {
        $this->activar(false);
    }

    public function down(): void
    {
        $this->activar(true);
    }

    private function activar(bool $activo): void
    {
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)->whereIn('id_menu', self::MENUS)->update(['activo' => $activo, 'updated_at' => now()]);
        }
    }
};
