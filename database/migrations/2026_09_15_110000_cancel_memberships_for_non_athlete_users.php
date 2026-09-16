<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $membresiaIds = DB::table('gimnasio.membresias as m')
            ->join('gimnasio.deportistas as d', 'd.id', '=', 'm.deportista_id')
            ->join('seguridad.users as u', 'u.id', '=', 'd.usuario_id')
            ->join('seguridad.cpu_userrole as r', 'r.id_userrole', '=', 'u.usr_tipo')
            ->where('r.role', '<>', 'DEPORTISTA')
            ->whereIn('m.estado', ['ACTIVO', 'ACTIVA', 'PENDIENTE_PAGO', 'CONGELADA'])
            ->pluck('m.id');

        if ($membresiaIds->isEmpty()) {
            return;
        }

        DB::table('gimnasio.membresias')
            ->whereIn('id', $membresiaIds)
            ->update([
                'estado' => 'CANCELADA',
                'renovacion_automatica' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No se reactiva automáticamente información histórica al revertir.
    }
};
