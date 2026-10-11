<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        foreach (['SUPERADMINISTRADOR', 'SUPERVISOR DE VENTAS'] as $role) {
            DB::table('seguridad.cpu_userrole')->updateOrInsert(
                ['role' => $role],
                [
                    'activo' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Solo elimina los roles si todavía no tienen usuarios asociados.
        foreach (['SUPERADMINISTRADOR', 'SUPERVISOR DE VENTAS'] as $role) {
            $id = DB::table('seguridad.cpu_userrole')
                ->where('role', $role)
                ->value('id_userrole');

            if (! $id) {
                continue;
            }

            $tieneUsuarios = DB::table('seguridad.users')
                ->where('id_userrole', $id)
                ->exists();

            if (! $tieneUsuarios) {
                DB::table('seguridad.cpu_userrole')
                    ->where('id_userrole', $id)
                    ->delete();
            }
        }
    }
};
