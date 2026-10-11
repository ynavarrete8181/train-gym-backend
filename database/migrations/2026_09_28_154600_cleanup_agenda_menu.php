<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ocultar = [
            'GIMNASIO-RECESOS',
            'GIMNASIO-ASIGNACION-HORARIOS',
        ];

        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->whereIn('id_menu', $ocultar)
                ->update([
                    'activo' => false,
                    'updated_at' => now(),
                ]);
        }

        $orden = [
            'GIMNASIO-HORARIOS' => 3,
            'GIMNASIO-DISPONIBILIDAD' => 4,
            'GIMNASIO-RESERVAS-DIA' => 5,
            'GIMNASIO-EXCEPCIONES-HORARIO' => 6,
        ];

        foreach ($orden as $codigo => $posicion) {
            foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
                DB::table($tabla)
                    ->where('id_menu', $codigo)
                    ->update([
                        'orden' => $posicion,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['seguridad.cpu_userrolefunction', 'seguridad.cpu_userfunction'] as $tabla) {
            DB::table($tabla)
                ->whereIn('id_menu', ['GIMNASIO-RECESOS', 'GIMNASIO-ASIGNACION-HORARIOS'])
                ->update([
                    'activo' => true,
                    'updated_at' => now(),
                ]);
        }
    }
};
