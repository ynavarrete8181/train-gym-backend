<?php

use App\Services\Gimnasio\CongelacionSinUsoServicio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Thalia Cedeño (MEMB-000031) no ha venido desde el 12/11/2026 por salud. Por pedido de la
 * administración se congela sin uso: se anula su cuota de $80 y el contrato queda congelado desde
 * el 12/11/2026. Al regresar se reactiva desde Membresías y la cuota se vuelve a emitir ese día.
 * Usa el mismo servicio que la pantalla; solo actúa si el contrato sigue cumpliendo las condiciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('membresias.membresias')->where('codigo_contrato', 'MEMB-000031')->value('id');
        if (! $id) {
            Log::warning('Congelar Thalia: no existe MEMB-000031.');
            return;
        }
        $servicio = app(CongelacionSinUsoServicio::class);
        $evaluacion = $servicio->evaluar((int) $id);
        if (! $evaluacion['disponible']) {
            Log::warning('Congelar Thalia: no se congela. '.$evaluacion['motivo']);
            return;
        }
        $usuario = (int) (DB::table('seguridad.users')->where('name', 'Root')->value('id') ?? 1);
        $servicio->congelar((int) $id, [
            'fecha_regreso' => '2026-12-26',
            'motivo_codigo' => 'ENFERMEDAD',
            'motivo' => 'Congelación sin uso de la membresía MEMB-000031 (Entrenamiento Híbrido) por enfermedad y recuperación, '
                .'autorizada por la administración: no ha asistido desde el 12/11/2026. Se anula la cuota '
                .$evaluacion['venta_numero'].' de $'.number_format($evaluacion['monto'], 2)
                .' y se vuelve a emitir al regresar. Regreso estimado referencial: 26/12/2026.',
        ], $usuario);
        Log::info('Congelar Thalia: congelada sin uso.');
    }

    public function down(): void
    {
        // Se revierte desde Membresías con "Reactivar membresía".
    }
};
