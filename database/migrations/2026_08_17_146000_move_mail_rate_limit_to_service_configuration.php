<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $limite = 10;

        if (Schema::hasTable('integraciones.configuraciones')) {
            $valor = DB::table('integraciones.configuraciones')
                ->where('clave', 'notificaciones.correos_por_minuto')
                ->value('valor');
            $limite = min(1000, max(1, (int) ($valor ?: 10)));
        }

        $servicio = DB::table('integraciones.servicios')->where('codigo', 'CORREO_ENVIAR')->first();
        if ($servicio) {
            $configuracion = is_array($servicio->configuracion)
                ? $servicio->configuracion
                : (json_decode((string) ($servicio->configuracion ?? '{}'), true) ?: []);
            $configuracion['correos_por_minuto'] = $limite;

            DB::table('integraciones.servicios')->where('id', $servicio->id)->update([
                'configuracion' => json_encode($configuracion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('integraciones.configuraciones');
    }

    public function down(): void
    {
        // La configuración permanece asociada al servicio de correo.
    }
};
